<?php

namespace App\Listeners;

use App\Application\Research\Llm\ModelAvailability;
use App\Application\Research\Planner\ModelRouter;
use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
use App\Domain\Research\Contracts\MemoryRepository;
use App\Domain\Research\Enums\JobRole;
use App\Domain\Research\Enums\JobStatus;
use App\Domain\Research\Enums\TaskStatus;
use App\Jobs\AdvanceResearchJob;
use App\Models\ResearchJob;
use App\Models\ResearchTask;
use Illuminate\Support\Str;
use Throwable;

/**
 * When a child sub-agent finishes (completed or failed), hand its outcome back
 * to the supervisor that spawned it and wake the (parked) supervisor. Branches
 * on the child's ROLE:
 *
 *  - WORKER (or sub-supervisor, recursive decomposition) → the existing
 *    behaviour: its report becomes the task's result and the task moves to
 *    AwaitingReview. It no longer eagerly loads the declared artifact into the
 *    supervisor's transcript — a Reviewer agent reads the real files itself
 *    (it has tools); the eager load is now reserved for the SUPERVISOR-FALLBACK
 *    path below, where it is the exception rather than the default LLM cost.
 *  - REVIEWER → its recorded verdict (research_jobs.review_verdict) is APPLIED
 *    to the task it was reviewing: accept → Done, revise → Pending with notes.
 *    No usable verdict (crashed, ran out of turns, or the model finished
 *    directly instead of calling submit_review) → fall back to the
 *    supervisor's own review_task, WITH the real artifact injected so that
 *    fallback turn doesn't need a read_file round-trip either.
 *
 * This is the resume half of a spawned sub-agent's pause — see StartResearch::
 * spawnWorker and ResearchOrchestrator::autoDelegateReadyTasks/autoDispatchReviews.
 */
class ResumeSupervisorOnChildDone
{
    public function __construct(
        private MemoryRepository $memory,
        private SandboxClient $sandbox,
        private ModelAvailability $availability,
        private ModelRouter $router,
    ) {}

    public function handleCompleted(object $event): void
    {
        $this->resume($event->jobId, failed: false);
    }

    public function handleFailed(object $event): void
    {
        $this->resume($event->jobId, failed: true, reason: $event->reason ?? 'worker failed');
    }

    private function resume(string $childId, bool $failed, string $reason = ''): void
    {
        $child = ResearchJob::find($childId);
        // Any job WITH A PARENT reports back — a plain worker, a sub-supervisor
        // (recursive decomposition), or a reviewer. Top-level jobs (no parent)
        // don't resume anyone.
        if (! $child || ! $child->parent_job_id) {
            return;
        }

        $parent = ResearchJob::find($child->parent_job_id);
        if (! $parent) {
            return;
        }

        // The parent was stopped (which stopped this child's siblings too, and
        // released its tasks). A late outcome from a child whose last turn was
        // already in flight must not resurrect that plan.
        if ($parent->status === JobStatus::Cancelled) {
            return;
        }

        if ($child->role === JobRole::Reviewer) {
            $this->resumeFromReviewer($child, $parent, $failed, $reason);

            return;
        }

        $this->resumeFromWorker($child, $parent, $failed, $reason);
    }

    /**
     * A WORKER (or sub-supervisor) finished. Record its outcome on the task it
     * was assigned to (found via research_tasks.child_job_id) and wake the parent.
     */
    private function resumeFromWorker(ResearchJob $worker, ResearchJob $parent, bool $failed, string $reason): void
    {
        $task = ResearchTask::where('child_job_id', $worker->id)->first();
        if (! $task) {
            return;
        }

        // Only act once — a worker in progress transitions exactly once.
        if ($task->status !== TaskStatus::InProgress) {
            return;
        }

        // A FAILED worker (crash / rate-limit / infra error) is NOT a review case:
        // there is no artifact to verify. Handle it DETERMINISTICALLY: send the
        // task straight back to Pending with a SHORT note. The orchestrator's
        // autoDelegateReadyTasks re-runs it (bounded by max_task_attempts) and the
        // supervisor PARKS with no LLM call — so failures can never balloon context.
        if ($failed) {
            $short = Str::limit(trim(preg_replace('/\s+/', ' ', $reason) ?? $reason), 300);

            // Learn availability from the failed job's OWN error: if it died because
            // the model was unavailable (rate-limit / gateway / timeout), put that
            // model into cooldown so the retry (and any other task) is routed to an
            // available one — no polling, no LLM.
            if ($this->availability->isAvailabilityFailure($short)) {
                $this->availability->markUnavailable($this->workerModel($worker), $short);
            }

            $task->update([
                'status' => TaskStatus::Pending,
                'result' => ResearchTask::WORKER_ERROR_PREFIX.$short,
            ]);

            $this->memory->appendToolObservation($parent, 'worker',
                "Worker for task #{$task->seq} \"{$task->title}\" failed: {$short}. "
                .'It will be retried automatically (up to the attempt limit); no action needed.');

            $this->wake($parent);

            return;
        }

        $result = (string) ($worker->final_report ?: '(the worker produced no report)');

        $task->update([
            'status' => TaskStatus::AwaitingReview,
            'result' => $result,
        ]);

        // No eager artifact load here any more — a dedicated Reviewer agent reads
        // the real files itself in its own fresh context (ResearchOrchestrator::
        // autoDispatchReviews spawns it deterministically, no LLM turn spent on
        // the supervisor to do so). The eager load is reserved for the
        // SUPERVISOR-FALLBACK path (resumeFromReviewer → fallbackToSupervisor),
        // which is the exception, not the default.
        $this->memory->appendToolObservation($parent, 'worker',
            "Worker finished task #{$task->seq} \"{$task->title}\". Its result:\n\n{$result}\n\n"
            .'A reviewer will verify it automatically; no action needed from you right now.');

        $this->wake($parent);
    }

    /**
     * A REVIEWER finished. Apply its recorded verdict to the task it was
     * reviewing (found via the reviewer job's own config, since a task's
     * child_job_id keeps pointing at its WORKER through review) — or fall back
     * to the supervisor's own review_task when there is no usable verdict.
     */
    private function resumeFromReviewer(ResearchJob $reviewer, ResearchJob $parent, bool $failed, string $reason): void
    {
        $taskId = $reviewer->config['review_task_id'] ?? null;
        $task = $taskId ? ResearchTask::find($taskId) : null;

        // Only act once — a task under review transitions exactly once.
        if (! $task || $task->status !== TaskStatus::Reviewing) {
            return;
        }

        if ($failed) {
            $short = Str::limit(trim(preg_replace('/\s+/', ' ', $reason) ?? $reason), 300);

            if ($this->availability->isAvailabilityFailure($short)) {
                $this->availability->markUnavailable($this->workerModel($reviewer), $short);
            }

            // A crashed/unavailable REVIEWER is not evidence against the WORKER's
            // task — do not requeue the task to Pending (that would re-run the
            // worker unnecessarily). Fall back to the supervisor's own judgment.
            $this->fallbackToSupervisor($parent, $task,
                "The reviewer for task #{$task->seq} \"{$task->title}\" failed to run ({$short}) — "
                .'falling back to your own review.');

            return;
        }

        $verdict = $reviewer->review_verdict;
        $choice = is_array($verdict) ? ($verdict['verdict'] ?? null) : null;

        if (! in_array($choice, ['accept', 'revise'], true)) {
            // The reviewer ran out of turns, or the model finished directly
            // instead of calling submit_review — no usable verdict. Fall back.
            $this->fallbackToSupervisor($parent, $task,
                "The reviewer for task #{$task->seq} \"{$task->title}\" finished without a verdict — "
                .'falling back to your own review.');

            return;
        }

        $notes = trim((string) ($verdict['notes'] ?? ''));

        if ($choice === 'accept') {
            $task->update(['status' => TaskStatus::Done]);
            $this->memory->appendToolObservation($parent, 'reviewer',
                "Reviewer ACCEPTED task #{$task->seq} \"{$task->title}\" as done.".($notes !== '' ? " {$notes}" : ''));
        } else {
            $task->update([
                'status' => TaskStatus::Pending,
                'brief' => $task->brief."\n\nREVISION NEEDED — fix this: ".($notes !== '' ? $notes : 'the reviewer rejected the result.'),
            ]);
            $this->memory->appendToolObservation($parent, 'reviewer',
                "Reviewer sent task #{$task->seq} \"{$task->title}\" back to revise: ".($notes !== '' ? $notes : '(no notes given)'));
        }

        $this->wake($parent);
    }

    /**
     * No usable reviewer verdict — hand the task to the supervisor's own
     * review_task fallback, WITH the real artifact injected (the eager-load
     * helper moved here from the worker-complete path, see class docblock).
     */
    private function fallbackToSupervisor(ResearchJob $parent, ResearchTask $task, string $note): void
    {
        $task->update(['status' => TaskStatus::AwaitingReview]);

        $artifacts = $this->loadArtifacts($parent, $task);

        $this->memory->appendToolObservation($parent, 'reviewer',
            "{$note}\n\n".$artifacts
            .'Review it with review_task (accept, or revise with notes).'
            .($artifacts !== ''
                ? ' The declared deliverable file(s) are shown above — review them directly; you do NOT need read_file unless something is missing.'
                : ''));

        $this->wake($parent);
    }

    private function wake(ResearchJob $parent): void
    {
        if ($parent->isRunnable()) {
            AdvanceResearchJob::dispatch($parent->id)->onQueue(config('research.queue.name'));
        }
    }

    /**
     * Read a finished task's declared output files from the SHARED workspace and
     * format them for the review turn. Best-effort: a missing/unreadable file or an
     * unreachable sandbox yields no block (the supervisor can still read_file), and
     * a pure-research task with no declared files yields none either.
     */
    private function loadArtifacts(ResearchJob $parent, ResearchTask $task): string
    {
        $paths = array_values(array_filter(
            array_map('trim', (array) ($task->outputs ?? [])),
            fn ($p) => is_string($p) && $p !== '' && $p !== '...'
                && ! str_ends_with($p, '/') && ! str_contains($p, '*'),
        ));
        if (! $paths) {
            return '';
        }

        $workspace = $parent->workspace_slug ?: ($parent->root_job_id ?: $parent->id);
        $blocks = [];
        foreach ($paths as $path) {
            try {
                $content = trim((string) ($this->sandbox->read($workspace, $path)['content'] ?? ''));
            } catch (SandboxException) {
                continue;   // reported missing — nothing to show, review guard will catch it
            } catch (Throwable) {
                return '';  // sandbox unreachable — can't load; let the model read_file
            }
            if ($content !== '') {
                $blocks[] = "----- {$path} -----\n".mb_strimwidth($content, 0, 6000, "\n…(truncated)");
            }
        }

        return $blocks ? "THE DELIVERABLE FILE(S) IN THE WORKSPACE:\n".implode("\n\n", $blocks)."\n\n" : '';
    }

    /** The model a job ran on — resolved exactly as ModelRouter pinned it that turn. */
    private function workerModel(ResearchJob $worker): string
    {
        return $this->router->modelFor($worker);
    }
}
