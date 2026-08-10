<?php

namespace App\Application\Research\Llm;

use App\Models\ModelBenchmark;
use Illuminate\Support\Facades\Cache;

/**
 * The "is this model usable RIGHT NOW" authority — a deterministic decision
 * Fariborz makes without asking the LLM. Availability is a FACT, not a judgment:
 *
 *  - A model enters a COOLDOWN the moment one of its own jobs fails with an
 *    availability error (rate-limit / timeout / 5xx / gateway / empty completion).
 *    This is learned from the failed job's real error — free, no probing. It is
 *    the concrete form of "analysing the data of a failed job to understand the
 *    model is unavailable for now, so hand the task to another model".
 *
 *  - Picking a REPLACEMENT model actively probes the candidate once (a cheap
 *    single-model /health) so we never hand a task from one dead model to another,
 *    and — when benchmark data exists — prefers the highest-scored candidate over
 *    whichever tier happened to be listed first in config (see orderByBenchmark).
 *
 * Cooldown lives in the cache with a short TTL (config
 * research.supervisor.model_unavailable_cooldown), so a throttled model is simply
 * skipped for a while and automatically reconsidered once the window passes.
 */
class ModelAvailability
{
    /** Error fragments that mean "the model/gateway, not the work, failed". */
    private const AVAILABILITY_SIGNALS = [
        '429', 'rate limit', 'ratelimit', 'rate_limit', 'too many requests',
        'quota', 'overloaded', 'capacity', 'timeout', 'timed out',
        'temporarily unavailable', 'service unavailable', 'bad gateway',
        '502', '503', '504', 'connection', 'econnrefused', 'empty completion',
        'empty response',
    ];

    public function __construct(private LiteLLMAdminClient $admin) {}

    /** Does this error text indicate the MODEL was unavailable (vs. bad work)? */
    public function isAvailabilityFailure(string $reason): bool
    {
        $r = mb_strtolower($reason);
        foreach (self::AVAILABILITY_SIGNALS as $needle) {
            if (str_contains($r, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Put a model into the availability cooldown, remembering why. */
    public function markUnavailable(string $model, string $reason): void
    {
        $model = trim($model);
        if ($model === '') {
            return;
        }

        $ttl = (int) config('research.supervisor.model_unavailable_cooldown', 120);
        Cache::put($this->key($model), mb_strimwidth(trim($reason), 0, 200, '…'), max(10, $ttl));
    }

    /** Is this model currently in cooldown (recently failed for availability)? */
    public function inCooldown(string $model): bool
    {
        return Cache::has($this->key(trim($model)));
    }

    public function cooldownReason(string $model): ?string
    {
        return Cache::get($this->key(trim($model)));
    }

    /**
     * Confirm a candidate model is usable: not in cooldown AND the gateway does
     * not report it unhealthy. Fails open on gateway trouble (see isModelHealthy),
     * so an unreachable gateway never blocks a handover — it just can't confirm.
     */
    public function confirmAvailable(string $model): bool
    {
        $model = trim($model);

        return $model !== '' && ! $this->inCooldown($model) && $this->admin->isModelHealthy($model);
    }

    /**
     * Pick the first usable replacement model from an ordered candidate list,
     * skipping the model we're handing off FROM. Returns null when none is usable
     * (caller then retries on the original — best effort).
     *
     * @param  list<string>  $candidates  models in preference order (re-ordered by benchmark score — see orderByBenchmark)
     */
    public function pickReplacement(array $candidates, string $avoid): ?string
    {
        $avoid = trim($avoid);
        foreach ($this->orderByBenchmark($candidates) as $model) {
            $model = trim((string) $model);
            if ($model === '' || $model === $avoid) {
                continue;
            }
            if ($this->confirmAvailable($model)) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Re-order candidates by ModelBenchmark score, best first — a handover
     * should go to a model already PROVEN to run this agent well, not
     * whichever tier happened to be listed first in config. A `broken` model
     * (fails the envelope probe — cannot even produce a valid tool call) is
     * skipped entirely: handing it a task just fails it again the same way.
     * Unrated models (no benchmark on record) sink to the end, in their
     * original relative order — no evidence either way is worse than measured
     * evidence, but still better than nothing, so they stay as a last resort
     * rather than being dropped. With NOTHING rated, every candidate is
     * "unrated" and this is a no-op — pickReplacement behaves exactly as
     * before benchmarking existed.
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function orderByBenchmark(array $candidates): array
    {
        $names = array_values(array_unique(array_map(fn ($m) => trim((string) $m), $candidates)));
        $rated = ModelBenchmark::ratedBy($names);

        $scored = [];
        $unrated = [];
        foreach ($candidates as $model) {
            $row = $rated->get(trim((string) $model));
            if ($row !== null && $row->rating === 'broken') {
                continue;
            }
            if ($row !== null && $row->score !== null) {
                $scored[] = [$model, (int) $row->score];
            } else {
                $unrated[] = $model;
            }
        }
        usort($scored, fn ($a, $b) => $b[1] <=> $a[1]);

        return [...array_map(fn ($s) => $s[0], $scored), ...$unrated];
    }

    private function key(string $model): string
    {
        return "research:model_cooldown:{$model}";
    }
}
