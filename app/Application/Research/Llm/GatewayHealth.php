<?php

namespace App\Application\Research\Llm;

use App\Models\ModelBenchmark;
use Illuminate\Support\Facades\Cache;

/**
 * The gateway's OWN responsiveness, learned from real calls — the load-control
 * counterpart to ModelAvailability (which learns "is THIS MODEL down" from a
 * failed job). This learns "is the GATEWAY AS A WHOLE slow right now" and turns
 * that into a concurrency BUDGET: how many sub-agents (workers + reviewers)
 * Fariborz may keep in flight. Classic AIMD (additive increase, multiplicative
 * decrease) — a deterministic control decision, exactly like delegation, review
 * dispatch and finish: the LLM is never asked "should we slow down".
 *
 * Cache-backed, not in-process: every queue worker is a different PHP process
 * advancing a different job, so the budget has to be shared state, not a
 * per-process counter. record() is a read-modify-write with NO locking — a
 * lost sample under a race between two workers is an acceptable trade for
 * never putting a lock on the hot path of every single model call.
 *
 * "slow" is calibrated PER MODEL when benchmark data exists (see
 * ModelBenchmark / slowThreshold): a model that is normally 90s would
 * otherwise read as permanently "slow" against one fixed slow_ms and stick
 * the concurrency budget at 1 forever. Falls back to the fixed number exactly
 * when nothing has been benchmarked, so this is a pure enhancement, not a
 * behaviour change, until the operator runs a benchmark.
 */
class GatewayHealth
{
    private const SAMPLES_KEY = 'research:gateway:samples';

    private const LIMIT_KEY = 'research:gateway:limit';

    /** Long enough to outlive any real gap between calls; short enough to not leak forever. */
    private const CACHE_TTL = 1800;

    /**
     * Record one gateway call's outcome: how long it took, whether it
     * succeeded, and (when known) which model ran it — HealthTrackingLlmClient
     * reads config('research.llm.model') at call time and passes it through so
     * state() can calibrate "slow" per-model instead of against one fixed
     * number (see slowThreshold). $model is optional so every existing/older
     * call site (and any cached sample recorded before this was added) keeps
     * working unchanged.
     */
    public function record(int $ms, bool $ok, string $error = '', string $model = ''): void
    {
        $samples = $this->samples();
        $samples[] = ['ms' => max(0, $ms), 'ok' => $ok, 'at' => now()->getTimestamp(), 'model' => trim($model)];

        $window = max(1, (int) config('research.gateway_load.window', 20));
        if (count($samples) > $window) {
            $samples = array_slice($samples, -$window);
        }

        Cache::put(self::SAMPLES_KEY, $samples, self::CACHE_TTL);
    }

    /**
     * A read-only view for humans (dashboard badge, trace events) — everything
     * needed to explain WHY the budget is what it is.
     *
     * @return array{state:string, avg_ms:int, samples:int, error_rate:float, limit:int, updated_at:?int}
     */
    public function snapshot(): array
    {
        $samples = $this->samples();
        $last = end($samples);

        return [
            'state' => $this->state(),
            'avg_ms' => $this->avgMs($samples),
            'samples' => count($samples),
            'error_rate' => $this->errorRate($samples),
            'limit' => $this->concurrencyLimit(),
            'updated_at' => $last !== false ? (int) $last['at'] : null,
        ];
    }

    /**
     * unknown  — not enough samples yet to say anything.
     * degraded — errors dominate the window (rate-limits, 5xx, empty completions).
     * slow     — calls are succeeding but taking a long time.
     * fast     — clean and quick, safe to add load.
     * normal   — neither notably fast nor slow, hold steady.
     *
     * "avg" is the mean of the whole window, deliberately not a percentile: a
     * hung/timed-out call records its real elapsed ms (see
     * HealthTrackingLlmClient), so ONE 10-minute turn pulls the mean toward
     * "slow" on its own — which is exactly the behaviour we want (that's the
     * operator's literal complaint: "taking 10 min on all of them").
     */
    public function state(): string
    {
        $samples = $this->samples();
        $minSamples = max(1, (int) config('research.gateway_load.min_samples', 3));
        if (count($samples) < $minSamples) {
            return 'unknown';
        }

        $errorRate = $this->errorRate($samples);
        if ($errorRate >= (float) config('research.gateway_load.error_rate_slow', 0.34)) {
            return 'degraded';
        }

        $avg = $this->avgMs($samples);
        if ($avg >= $this->slowThreshold($samples)) {
            return 'slow';
        }
        if ($avg <= (int) config('research.gateway_load.fast_ms', 20000) && $errorRate === 0.0) {
            return 'fast';
        }

        return 'normal';
    }

    /**
     * The "slow" bar for THIS window: whichever is higher of the fixed
     * research.gateway_load.slow_ms and slow_factor × the expected baseline
     * for the models actually in the window. Without this, one global slow_ms
     * misjudges a model that is NORMALLY 90s as permanently "slow" (the
     * concurrency budget then sticks at 1 forever) — see expectedBaselineMs.
     * Falls back to the fixed number exactly when there's no benchmark data to
     * calibrate against, so behaviour is unchanged until the operator runs a
     * benchmark.
     *
     * @param  list<array{ms:int, ok:bool, at:int, model?:string}>  $samples
     */
    private function slowThreshold(array $samples): int
    {
        $configured = (int) config('research.gateway_load.slow_ms', 75000);
        $expected = $this->expectedBaselineMs($samples);
        if ($expected === null) {
            return $configured;
        }

        $factor = (float) config('research.gateway_load.slow_factor', 2.5);

        return max($configured, (int) round($expected * $factor));
    }

    /**
     * "What should a normal call in THIS window cost" — the mean of the
     * benchmarked median_ms of the models actually seen in it, instead of one
     * global number. Null (→ fall back to the fixed slow_ms) when the window
     * has no model info (samples recorded before this was added — no `model`
     * key at all) or none of its models have been benchmarked yet; a window
     * full of unrated models must NOT silently collapse to a zero/undefined
     * baseline that would then call every call "slow".
     *
     * @param  list<array{ms:int, ok:bool, at:int, model?:string}>  $samples
     */
    private function expectedBaselineMs(array $samples): ?int
    {
        $models = array_values(array_unique(array_filter(array_map(
            fn ($s) => trim((string) ($s['model'] ?? '')), $samples,
        ))));
        if (empty($models)) {
            return null;
        }

        $medians = ModelBenchmark::ratedBy($models)
            ->pluck('median_ms')
            ->filter(fn ($ms) => $ms !== null);

        return $medians->isEmpty() ? null : (int) round($medians->avg());
    }

    /** Shorthand the orchestrator/HTTP client use to decide "hold back" vs "go". */
    public function isStrained(): bool
    {
        return in_array($this->state(), ['slow', 'degraded'], true);
    }

    /**
     * The AIMD budget: how many sub-agents the gateway can take right now.
     * Re-evaluated at most once per adjust_interval_seconds — the sample window
     * already smooths the SIGNAL, this smooths how often we act on it, so one
     * stray fast/slow sample can't whipsaw the limit turn to turn.
     */
    public function concurrencyLimit(): int
    {
        $min = max(1, (int) config('research.gateway_load.min_concurrency', 1));
        $max = max($min, (int) config('research.gateway_load.max_concurrency', 6));
        $base = min($max, max($min, (int) config('research.gateway_load.base_concurrency', 3)));

        $samples = $this->samples();
        $last = end($samples);

        // No traffic for a while → a fresh start must not inherit yesterday's
        // punishment (a halved-to-1 limit from an outage hours ago would
        // otherwise throttle a perfectly healthy gateway forever).
        $idleSeconds = max(1, (int) config('research.gateway_load.idle_reset_seconds', 600));
        if ($last === false || (now()->getTimestamp() - (int) $last['at']) >= $idleSeconds) {
            if ($this->storedLimit() !== $base) {
                $this->storeLimit($base);
            }

            return $base;
        }

        $stored = Cache::get(self::LIMIT_KEY);
        if (! is_array($stored)) {
            // No prior evaluation at all — establish the baseline and start the
            // interval clock. Growth/decay only applies from the NEXT
            // evaluation onward (at least one adjust_interval later), so a
            // fresh boot can't leap straight from base to base+1 on its very
            // first sample before ever having "held" at base for an interval.
            $this->storeLimit($base);

            return $base;
        }

        $limit = max($min, min($max, (int) ($stored['limit'] ?? $base)));
        $at = (int) ($stored['at'] ?? 0);

        $interval = max(1, (int) config('research.gateway_load.adjust_interval_seconds', 20));
        if ((now()->getTimestamp() - $at) < $interval) {
            return $limit;   // too soon to re-evaluate — return what's on record
        }

        $new = match ($this->state()) {
            'degraded', 'slow' => max($min, intdiv($limit, 2)),          // multiplicative decrease
            'fast' => min($max, $limit + 1),                              // additive increase — "a little"
            default => $limit,                                            // normal/unknown — hold steady
        };

        if ($new !== $limit) {
            $this->storeLimit($new);
        }

        return $new;
    }

    /** Drop everything — tests, or an operator resetting after a manual fix. */
    public function reset(): void
    {
        Cache::forget(self::SAMPLES_KEY);
        Cache::forget(self::LIMIT_KEY);
    }

    private function storedLimit(): ?int
    {
        $stored = Cache::get(self::LIMIT_KEY);

        return is_array($stored) ? (int) ($stored['limit'] ?? null) : null;
    }

    private function storeLimit(int $limit): void
    {
        Cache::put(self::LIMIT_KEY, ['limit' => $limit, 'at' => now()->getTimestamp()], self::CACHE_TTL);
    }

    /** @return list<array{ms:int, ok:bool, at:int, model?:string}> */
    private function samples(): array
    {
        $s = Cache::get(self::SAMPLES_KEY);

        return is_array($s) ? $s : [];
    }

    /** @param  list<array{ms:int, ok:bool, at:int, model?:string}>  $samples */
    private function avgMs(array $samples): int
    {
        if (empty($samples)) {
            return 0;
        }

        return (int) round(array_sum(array_column($samples, 'ms')) / count($samples));
    }

    /** @param  list<array{ms:int, ok:bool, at:int, model?:string}>  $samples */
    private function errorRate(array $samples): float
    {
        if (empty($samples)) {
            return 0.0;
        }

        $failed = count(array_filter($samples, fn ($s) => ! $s['ok']));

        return $failed / count($samples);
    }
}
