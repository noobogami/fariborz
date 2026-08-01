<?php

namespace App\Listeners;

use App\Domain\Research\Contracts\MemoryRepository;
use App\Domain\Research\Enums\TaskStatus;
use App\Jobs\AdvanceResearchJob;
use App\Models\ResearchJob;
use App\Models\ResearchTask;

/**
 * When a WORKER sub-agent finishes (completed or failed), hand its result back
 * to the supervisor that delegated it: record it on the task, feed it into the
 * supervisor's memory as an observation, and wake the (parked) supervisor so it
 * reviews and moves on. This is the resume half of delegate_task's pause.
 */
class ResumeSupervisorOnChildDone
{
    public function __construct(private MemoryRepository $memory) {}

    public function handleCompleted(object $event): void
    {
        $this->resume($event->jobId, failed: false);
    }

    public function handleFailed(object $event): void
    {
        $this->resume($event->jobId, failed: true, reason: $event->reason ?? 'worker failed');
    }

    private function resume(string $workerId, bool $failed, string $reason = ''): void
    {
        $worker = ResearchJob::find($workerId);
        // Any job WITH A PARENT reports back — a plain worker OR a sub-supervisor
        // (recursive decomposition). Top-level jobs (no parent) don't resume anyone.
        if (! $worker || ! $worker->parent_job_id) {
            return;
        }

        $task = ResearchTask::where('child_job_id', $workerId)->first();
        $parent = ResearchJob::find($worker->parent_job_id);
        if (! $task || ! $parent) {
            return;
        }

        // Only act once — a worker in progress becomes awaiting_review exactly once.
        if ($task->status !== TaskStatus::InProgress) {
            return;
        }

        $result = $failed
            ? "Worker FAILED task #{$task->seq}: {$reason}. Its partial output (if any):\n".($worker->final_report ?: '(none)')
            : (string) ($worker->final_report ?: '(the worker produced no report)');

        $task->update([
            'status' => TaskStatus::AwaitingReview,
            'result' => $result,
        ]);

        // Feed the result into the supervisor's transcript so its next turn sees it.
        $this->memory->appendToolObservation($parent, 'worker',
            "Worker finished task #{$task->seq} \"{$task->title}\". Its result:\n\n{$result}\n\n"
            .'Review it with review_task (accept, or revise with notes).');

        // Wake the parked supervisor (guard against double-dispatch on a live one).
        if ($parent->isRunnable()) {
            AdvanceResearchJob::dispatch($parent->id)->onQueue(config('research.queue.name'));
        }
    }
}
