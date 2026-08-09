<?php

namespace App\Application\Research;

use App\Domain\Research\Contracts\MemoryRepository;
use App\Domain\Research\Contracts\ResearchJobRepository;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
use App\Domain\Research\Enums\JobRole;
use App\Domain\Research\Enums\TaskStatus;
use App\Jobs\AdvanceResearchJob;
use App\Models\ResearchJob;
use App\Models\ResearchTask;

/**
 * Stopping a job stops the WHOLE sub-tree under it. A supervisor with workers
 * (and reviewers, and sub-supervisors with workers of their own) is one run from
 * the operator's point of view: cancelling only the parent used to leave its
 * sub-agents looping — burning model calls and writing into the shared workspace
 * long after the run was stopped.
 *
 * Cancellation is DB state, not a queue operation: a queued/in-flight iteration
 * for a cancelled job returns immediately (ResearchOrchestrator::advance checks
 * isRunnable, and CancellationGuardrail re-reads status + ancestors), so there
 * is nothing to purge from the queue.
 *
 * The reverse direction matters too: stopping a single WORKER must not leave its
 * supervisor parked forever waiting for a sub-agent that will never report back
 * (a cancelled job fires no completed/failed event), so the task it owned is
 * released and the parent is woken — see releaseParentTask().
 */
class CancelResearch
{
    /** Marker on a task whose sub-agent was stopped by hand. */
    public const STOPPED_NOTE = '⚠ Stopped by Father: the sub-agent for this task was cancelled.';

    public function __construct(
        private ResearchJobRepository $jobs,
        private MemoryRepository $memory,
        private TraceRecorder $trace,
    ) {}

    /**
     * Cancel $job and every sub-agent beneath it.
     *
     * @return int how many jobs were actually transitioned (including $job)
     */
    public function handle(ResearchJob $job, string $reason = 'Job cancelled by Father.'): int
    {
        $cancelled = $this->stop($job, $reason);

        foreach ($this->descendants($job) as $child) {
            $cancelled += $this->stop($child,
                "Cancelled because its parent job ({$job->slug}) was stopped.");
        }

        // Only the job the operator actually stopped reports back to its parent —
        // a descendant's parent is inside the cancelled sub-tree anyway.
        $this->releaseParentTask($job);

        return $cancelled;
    }

    /** Cancel one job (idempotent) and free any task its own sub-agents held. */
    private function stop(ResearchJob $job, string $reason): int
    {
        if ($job->status->isTerminal()) {
            return 0;   // already finished/failed/cancelled — leave it alone
        }

        $this->jobs->markCancelled($job);
        $this->jobs->setActivity($job, null);
        $this->trace->record($job, EventType::Cancelled, $reason);

        // A supervisor's in-flight tasks are no longer in flight: their workers
        // and reviewers were just cancelled too. Put them back to Pending so the
        // state is honest in the UI and a later continue/rerun re-delegates them
        // instead of waiting on sub-agents that are gone.
        ResearchTask::where('research_job_id', $job->id)
            ->whereIn('status', [TaskStatus::InProgress->value, TaskStatus::Reviewing->value])
            ->update(['status' => TaskStatus::Pending->value]);

        return 1;
    }

    /**
     * Every job under $job, breadth-first. Traverses through already-terminal
     * jobs too — a completed sub-supervisor could still have a straggler worker.
     *
     * @return list<ResearchJob>
     */
    private function descendants(ResearchJob $job): array
    {
        $all = [];
        $frontier = [$job->id];

        // Bounded: the tree is small (supervisor → workers → reviewers), and the
        // depth guard means a cyclic parent chain can never spin here.
        for ($depth = 0; $frontier && $depth < 10; $depth++) {
            $children = ResearchJob::whereIn('parent_job_id', $frontier)->get()->all();
            if (! $children) {
                break;
            }
            $all = array_merge($all, $children);
            $frontier = array_map(fn (ResearchJob $c) => $c->id, $children);
        }

        return $all;
    }

    /**
     * The stopped job was a sub-agent of a still-running supervisor: release the
     * task it owned and wake the parent, or it parks forever (a cancelled job
     * fires no ResearchCompleted/ResearchFailed, so ResumeSupervisorOnChildDone
     * never runs for it).
     *
     *  - a WORKER's task → Failed. Stopping it by hand is a decision, not a
     *    transient error, so it is NOT re-delegated; dependents stay blocked and
     *    the supervisor settles + reports deterministically.
     *  - a REVIEWER's task → back to AwaitingReview, i.e. judged by the next
     *    review pass instead of being lost mid-verdict. The work itself was fine.
     */
    private function releaseParentTask(ResearchJob $job): void
    {
        if (! $job->parent_job_id) {
            return;
        }

        $parent = ResearchJob::find($job->parent_job_id);
        if (! $parent || ! $parent->isRunnable()) {
            return;
        }

        if ($job->role === JobRole::Reviewer) {
            $taskId = $job->config['review_task_id'] ?? null;
            $task = $taskId ? ResearchTask::find($taskId) : null;
            if (! $task || $task->status !== TaskStatus::Reviewing) {
                return;
            }

            $task->update(['status' => TaskStatus::AwaitingReview]);
            $note = "The reviewer for task #{$task->seq} \"{$task->title}\" was stopped by Father — "
                .'the task goes back for review.';
        } else {
            $task = ResearchTask::where('child_job_id', $job->id)->first();
            if (! $task || $task->status !== TaskStatus::InProgress) {
                return;
            }

            $task->update(['status' => TaskStatus::Failed, 'result' => self::STOPPED_NOTE]);
            $note = "The worker for task #{$task->seq} \"{$task->title}\" was stopped by Father — "
                .'the task is marked failed and will NOT be retried. Continue with the remaining tasks.';
        }

        $this->memory->appendToolObservation($parent, 'system', $note);
        $this->trace->record($parent, EventType::Observation, $note, ['task' => $task->seq, 'child_job_id' => $job->id]);

        AdvanceResearchJob::dispatch($parent->id)->onQueue(config('research.queue.name'));
    }
}
