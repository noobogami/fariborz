<?php

namespace App\Infrastructure\Research\Llm;

use App\Application\Research\Llm\GatewayHealth;
use App\Domain\Research\Contracts\LlmClient;
use Throwable;

/**
 * Decorates the real LlmClient to TIME every single model call and feed
 * GatewayHealth — the one place every call passes through (planner,
 * GoalComprehension, FailureDiagnosis, and anything added later), so gateway
 * load control gets full coverage with zero call-site edits. Same shape as
 * why ModelRouter pins a tier in config instead of every caller knowing about
 * tiers.
 *
 * An empty completion is recorded as a FAILURE even though complete() returns
 * normally — ModelAvailability already treats an empty completion as an
 * availability signal, and it typically means the model burned its whole
 * output budget on hidden reasoning, which is exactly the kind of struggling
 * behaviour the load control needs to see and react to.
 *
 * Every sample also carries WHICH MODEL made the call — read from
 * config('research.llm.model') at call time, the same config key
 * ModelRouter/ModelBenchmark pin before calling complete(). GatewayHealth uses
 * this to calibrate its "slow" threshold per model instead of one fixed
 * number for every model in the window (see GatewayHealth::slowThreshold).
 */
class HealthTrackingLlmClient implements LlmClient
{
    public function __construct(private LlmClient $inner, private GatewayHealth $health) {}

    public function complete(string $system, array $messages, ?callable $onProgress = null): string
    {
        $start = microtime(true);
        $model = trim((string) config('research.llm.model', ''));

        try {
            $result = $this->inner->complete($system, $messages, $onProgress);
        } catch (Throwable $e) {
            // The real elapsed time on a hung/timed-out call matters: a call
            // that took 10 minutes before failing should pull the average
            // toward "slow"/"degraded" on its own, not just count as one
            // generic error among many.
            $this->health->record($this->elapsedMs($start), false, $e->getMessage(), $model);

            throw $e;   // unchanged — callers still see the real error
        }

        $this->health->record($this->elapsedMs($start), trim($result) !== '', model: $model);

        return $result;
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
