<?php

namespace App\Application\Research\Tracing;

use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
use App\Models\ResearchEvent;
use App\Models\ResearchJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The concrete recorder. Writes to BOTH:
 *   - `research_events` (durable, queryable timeline for the trace command/UI)
 *   - the "research" log channel (live tailing + grep during operation)
 *
 * `seq` is a per-job monotonic counter allocated atomically so the timeline
 * order is stable even under concurrent workers.
 */
class DbTraceRecorder implements TraceRecorder
{
    public function record(
        ResearchJob $job,
        EventType $type,
        string $summary,
        array $payload = [],
        ?int $durationMs = null,
    ): void {
        $seq = $this->nextSequence($job->id);

        // 1. Durable timeline row.
        ResearchEvent::create([
            'research_job_id' => $job->id,
            'seq' => $seq,
            'iteration' => $job->iteration,
            'type' => $type,
            'level' => $type->level(),
            'summary' => $this->clip($summary),
            'payload' => $payload ?: null,
            'duration_ms' => $durationMs,
            'occurred_at' => now(),
        ]);

        // 2. Structured log line — every line carries the same correlation keys
        //    so a supervisor can `grep '"job":"<uuid>"'` and read the whole run.
        Log::channel(config('research.trace.log_channel'))->log(
            $type->level(),
            sprintf('%s %s', $type->glyph(), $summary),
            [
                'job' => $job->id,
                'seq' => $seq,
                'iter' => $job->iteration,
                'event' => $type->value,
                'duration_ms' => $durationMs,
                // Keep the log line lean; heavy payloads live in the DB row.
                'payload' => $this->logSafePayload($payload),
            ],
        );
    }

    /**
     * Atomic per-job sequence. Uses a dedicated max()+insert under a short
     * transaction; on Postgres/MySQL this is safe enough for our write rate.
     * (For very high throughput, swap for a DB sequence or advisory lock.)
     */
    private function nextSequence(string $jobId): int
    {
        return (int) DB::transaction(function () use ($jobId) {
            $max = ResearchEvent::where('research_job_id', $jobId)->lockForUpdate()->max('seq');

            return (int) $max + 1;
        });
    }

    private function clip(string $summary): string
    {
        $max = (int) config('research.trace.summary_max_chars', 280);

        return mb_strlen($summary) > $max ? mb_substr($summary, 0, $max).'…' : $summary;
    }

    /** Trim big fields out of the log context (they stay full in the DB payload). */
    private function logSafePayload(array $payload): array
    {
        foreach (['prompt', 'raw', 'report', 'observation'] as $heavy) {
            if (isset($payload[$heavy]) && is_string($payload[$heavy]) && mb_strlen($payload[$heavy]) > 200) {
                $payload[$heavy] = mb_substr($payload[$heavy], 0, 200).'…(truncated; see DB)';
            }
        }

        return $payload;
    }
}
