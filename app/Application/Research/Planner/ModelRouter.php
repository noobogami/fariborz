<?php

namespace App\Application\Research\Planner;

use App\Application\Research\Llm\ModelCatalog;
use App\Models\ModelBenchmark;
use App\Models\ResearchJob;
use Illuminate\Support\Facades\Log;

/**
 * Per-task model routing without hardcoding a model per role — and the ONE place
 * a tier becomes a concrete model id.
 *
 * Each job carries a capability TIER (light / standard / hard — see
 * config('research.llm.tiers')). A worker's tier is the one the supervisor
 * assigned to its task when planning; solo jobs and a supervisor's own
 * planning/review turns fall back to `default_tier`. This resolves that tier to
 * a model id and pins it into config('research.llm.model') for THIS turn, so the
 * LLM client transparently sends the right model — it reads config at call time
 * and knows nothing about tiers. Via the gateway (LiteLLM) a tier's model can be
 * a local Ollama model or a cloud one, so a task's difficulty routes it offline
 * or to the cloud.
 *
 * A tier whose model is blank falls back to the global `research.llm.model`, so
 * with every tier unset the behaviour is identical (one model). The result then
 * goes through ModelCatalog, because a gateway model name is an alias the
 * operator can rename or delete at any time — resolving against the live
 * catalogue is what stops a stale setting from failing every turn of every job.
 *
 * Everything that needs to know "which model would this tier run on" (the
 * orchestrator's availability rerouting, the supervisor's review turn) MUST come
 * through modelForTier() rather than reading config itself — two copies of this
 * fallback chain would drift.
 *
 * modelForTier() also carries the ONE benchmark-driven override this app makes
 * without asking the operator: a model rated `broken` (fails the tool-call
 * envelope — see App\Application\Research\Llm\ModelBenchmark) is never sent a
 * turn, because that is a guaranteed invalid_llm_response, not a worse outcome.
 * See avoidBroken() for the fail-open rules that keep this conservative.
 */
class ModelRouter
{
    /**
     * Per-model benchmark rating, memoised for THIS instance — modelForTier()
     * sits on the hot path of every turn, and a repeated call within the same
     * turn (apply(), then the orchestrator re-deriving the same tier) must not
     * cost a fresh query each time. Keyed by model name; null values (queried,
     * nothing on record) are cached too, same as a hit.
     *
     * @var array<string, ModelBenchmark|null>
     */
    private array $ratingCache = [];

    public function __construct(private ModelCatalog $catalog) {}

    /**
     * Resolve the tier for this job and pin its model into the live config for
     * the current turn. Returns the model id actually applied (for tracing), or
     * null when nothing could be resolved (no model configured and an empty or
     * unreachable gateway) — the client then fails loudly on its own.
     */
    public function apply(ResearchJob $job): ?string
    {
        $tier = $this->tierFor($job);
        config(['research.llm.active_tier' => $tier]);

        $model = $this->modelForTier($tier);
        if ($model === '') {
            return null;
        }

        config(['research.llm.model' => $model]);

        return $model;
    }

    /**
     * The concrete model a tier runs on: the tier's own model, else the global
     * default, resolved against the gateway's live catalogue. '' when there is
     * nothing usable at all.
     */
    public function modelForTier(string $tier): string
    {
        $model = trim((string) config("research.llm.tiers.$tier.model", ''));
        if ($model === '') {
            $model = trim((string) config('research.llm.model', ''));
        }

        return $this->avoidBroken($this->catalog->resolve($model));
    }

    /** The model a job's own tier resolves to, without touching live config. */
    public function modelFor(ResearchJob $job): string
    {
        return $this->modelForTier($this->tierFor($job));
    }

    /**
     * §C of the benchmark-wiring spec: a model rated `broken` must never be
     * routed to. Only `broken` triggers this — a low score or a `weak`/`usable`
     * rating is left completely alone, because that's the operator's own
     * choice and benchmarking is meant to inform it, not overrule it.
     *
     * Fails open at every step: no rating on record for $resolved, an
     * unreadable catalogue (nothing to confirm an alternative is even live),
     * or no OTHER live model rated better than broken all return $resolved
     * unchanged — a DB hiccup or a gateway blip must never turn an optional
     * calibration signal into a new way for every turn to fail.
     */
    private function avoidBroken(string $resolved): string
    {
        if ($resolved === '') {
            return $resolved;
        }

        $row = $this->ratingFor($resolved);
        if ($row === null || $row->rating !== 'broken') {
            return $resolved;
        }

        $names = $this->catalog->names();
        if ($names === null) {
            return $resolved;   // catalogue unreadable — nothing to confirm, trust config
        }

        $replacement = $this->bestRatedAmong($names, $resolved);
        if ($replacement === null) {
            return $resolved;   // nothing better on record either — best effort
        }

        // The ONLY place this reroute needs to be "recorded so it's traceable"
        // (spec §C.2): apply() pins the RETURNED model into
        // research.llm.model, and LlmPlanner's trace payload records that
        // config value per turn — so the job's own timeline already shows the
        // real model used, differing visibly from what Settings has
        // configured. This log line is the operational (non-per-job) trail.
        Log::info('model_router.broken_reroute', ['from' => $resolved, 'to' => $replacement]);

        return $replacement;
    }

    /** Memoised rating lookup for ONE model — see $ratingCache. */
    private function ratingFor(string $model): ?ModelBenchmark
    {
        if (! array_key_exists($model, $this->ratingCache)) {
            $this->ratingCache[$model] = ModelBenchmark::ratedBy([$model])->get($model);
        }

        return $this->ratingCache[$model];
    }

    /**
     * The highest-scored live model in $names that isn't $avoid and isn't
     * itself rated broken. Deterministic regardless of DB row order: it walks
     * $names (the gateway's own order) and keeps the best score seen so far.
     *
     * @param  list<string>  $names
     */
    private function bestRatedAmong(array $names, string $avoid): ?string
    {
        $rated = ModelBenchmark::ratedBy($names);

        $best = null;
        foreach ($names as $name) {
            if ($name === $avoid) {
                continue;
            }
            $row = $rated->get($name);
            if ($row === null || $row->rating === null || $row->rating === 'broken' || $row->score === null) {
                continue;
            }
            if ($best === null || (int) $row->score > (int) $best->score) {
                $best = $row;
            }
        }

        return $best?->model;
    }

    /** The tier name applied to this job, always a key that exists in config. */
    public function tierFor(ResearchJob $job): string
    {
        $tiers = (array) config('research.llm.tiers', []);
        $requested = $job->config['tier'] ?? null;

        if (is_string($requested) && isset($tiers[$requested])) {
            return $requested;
        }

        $default = (string) config('research.llm.default_tier', 'standard');
        if (isset($tiers[$default])) {
            return $default;
        }

        // Last resort: whatever the first configured tier is (or a safe literal).
        return (string) (array_key_first($tiers) ?? 'standard');
    }
}
