<?php

namespace App\Domain\Research\Contracts;

use App\Domain\Research\Enums\EventType;
use App\Models\ResearchJob;

/**
 * The one funnel through which EVERYTHING the agent does is recorded.
 *
 * Every call:
 *   1. appends a row to `research_events` (the durable, queryable timeline), and
 *   2. writes a structured line to the "research" log channel.
 *
 * Because every orchestrator + tool + guardrail action goes through here, the
 * timeline is guaranteed complete and in order — which is exactly what a
 * supervisor needs to reconstruct "started with X, used tool Y, found Z,
 * then asked X'..." days after the fact.
 */
interface TraceRecorder
{
    /**
     * Record one timeline event.
     *
     * @param  string  $summary  a one-line, human-readable description
     * @param  array  $payload  full structured detail (args, results, prompts…)
     * @param  int|null  $durationMs  how long the underlying operation took, if relevant
     */
    public function record(
        ResearchJob $job,
        EventType $type,
        string $summary,
        array $payload = [],
        ?int $durationMs = null,
    ): void;
}
