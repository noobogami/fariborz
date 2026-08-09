<?php

namespace App\Application\Research\Planner;

use App\Application\Research\Llm\ModelCatalog;
use App\Models\ResearchJob;

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
 */
class ModelRouter
{
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

        return $this->catalog->resolve($model);
    }

    /** The model a job's own tier resolves to, without touching live config. */
    public function modelFor(ResearchJob $job): string
    {
        return $this->modelForTier($this->tierFor($job));
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
