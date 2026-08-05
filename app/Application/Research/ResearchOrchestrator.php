<?php

namespace App\Application\Research;

use App\Application\Research\Guardrails\GuardrailPipeline;
use App\Application\Research\Human\HumanAvailabilityService;
use App\Application\Research\Llm\ModelAvailability;
use App\Application\Research\Planner\FailureDiagnosis;
use App\Application\Research\Planner\GoalComprehension;
use App\Application\Research\Planner\InvalidDecisionException;
use App\Application\Research\Tools\ArtifactChecks;
use App\Application\Research\Tools\ToolRegistry;
use App\Application\Research\Tools\ToolRunner;
use App\Domain\Research\Contracts\HumanQuestionRepository;
use App\Domain\Research\Contracts\MemoryRepository;
use App\Domain\Research\Contracts\Planner;
use App\Domain\Research\Contracts\ResearchJobRepository;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
use App\Domain\Research\Enums\JobRole;
use App\Domain\Research\Enums\StepType;
use App\Domain\Research\Enums\TaskStatus;
use App\Domain\Research\ValueObjects\FinishDecision;
use App\Domain\Research\ValueObjects\GuardrailVerdict;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolCall;
use App\Events\ResearchAdvanced;
use App\Events\ResearchCompleted;
use App\Events\ResearchFailed;
use App\Jobs\AdvanceResearchJob;
use App\Models\ResearchJob;
use App\Models\ResearchTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The planner loop — but exactly ONE iteration per call. The "loop" is the
 * queue: each iteration ends by re-dispatching AdvanceResearchJob unless the
 * job finished. This makes the whole thing crash-safe and resumable, because
 * all state lives in the database, never in a long-lived worker.
 *
 * The LLM only ever DECIDES. This class does the executing, storing, limiting,
 * resuming, and tracing.
 */
class ResearchOrchestrator
{
    public function __construct(
        private Planner $planner,
        private ToolRegistry $registry,
        private ToolRunner $runner,
        private GuardrailPipeline $guardrails,
        private ResearchJobRepository $jobs,
        private MemoryRepository $memory,
        private HumanQuestionRepository $humanQuestions,
        private HumanAvailabilityService $humans,
        private TraceRecorder $trace,
        private StartResearch $start,
        private GoalComprehension $comprehension,
        private ModelAvailability $availability,
        private FailureDiagnosis $diagnosis,
        private ArtifactChecks $artifactChecks,
    ) {}

    public function advance(string $jobId): void
    {
        $job = $this->jobs->find($jobId);

        // Idempotency: only a running job advances. Guards double-dispatch and
        // races between a cancellation and a queued iteration.
        if (! $job->isRunnable()) {
            return;
        }

        $context = $this->buildContext($job);

        // 1. Preflight guardrails can STOP the whole job (limit/timeout/cancel).
        if ($stop = $this->guardrails->preflight($context)) {
            $this->finalizeByGuardrail($job, $stop);

            return;
        }

        // 1a2. UNDERSTAND before planning. A supervisor spends ONE bounded turn
        //      extracting the user's real spec (constraints, ordering, deliverable)
        //      from the raw goal, so the plan honors it instead of the weak model
        //      re-guessing intent — differently — every turn. Runs once (before any
        //      task exists), then reschedules so the next turn plans WITH the spec.
        if ($this->needsComprehension($job)) {
            $this->comprehension->analyze($job);
            $this->reschedule($job);

            return;
        }

        // 1b. SUPERVISOR = deterministic control flow ("blueprint first, model
        //     second"). The ORCHESTRATOR — not the weak model — delegates every
        //     ready task and decides when to wait. The model is only asked for the
        //     bounded steps it's good at: plan, review ONE task, or assemble/finish.
        //     This is what killed the delegate→blocked→delegate thrash loop.
        if ($job->isSupervisor()) {
            if ($this->autoDelegateReadyTasks($job) > 0) {
                $context = $this->buildContext($job);   // ready tasks are now running
            }
            // Every task AWAITING REVIEW is dispatched deterministically too: a
            // pre-gate that can only reject, otherwise a per-task Reviewer agent
            // (or, if reviewing is disabled, left for the LLM fallback below).
            if ($this->autoDispatchReviews($job) > 0) {
                $context = $this->buildContext($job);
            }
            if ($job->supervisorShouldWait()) {
                $this->jobs->setActivity($job, 'awaiting_worker');   // wait for workers — no LLM call, no loop

                return;
            }

            // DETERMINISTIC FINISH. Getting here as a supervisor with no work in
            // flight, nothing awaiting review, and no ready task means every task
            // is terminal (Done, Failed, or permanently blocked by a failed dep) —
            // a FACT, not a judgment. Assemble the report in code instead of asking
            // the weak model, which is exactly the step that looped into empty
            // responses and failed the job. A task Awaiting review is the one case
            // left to the LLM (the accept/revise quality verdict), so it is excluded.
            if ($this->supervisorAllSettled($job)) {
                $this->finishSupervisorDeterministically($job, $context);

                return;
            }
        }

        // 2. Ask the brain for the single next action.
        //    Mark the live phase so the UI shows "thinking (calling the model)".
        $this->jobs->setActivity($job, 'thinking');
        try {
            $decision = $this->planner->decide($context);
            $this->jobs->resetParseFailures($job);
        } catch (InvalidDecisionException $e) {
            $this->handleInvalidDecision($job, $e);

            return;
        }

        // Record the reasoning both as a step and in memory (assistant turn).
        $this->jobs->recordStep($job, $context->iteration, StepType::Thought, $decision->thought());
        $this->memory->appendAssistant($job, json_encode($decision->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // 3. FINISH — persist the report and stop the loop.
        if ($decision instanceof FinishDecision) {
            $this->finish($job, $context, $decision);

            return;
        }

        // 4. TOOL CALL.
        /** @var ToolCall $decision */
        $this->trace->record($job, EventType::ToolSelected,
            "Chose {$decision->tool}: ".$this->firstLine($decision->thought()),
            ['tool' => $decision->tool, 'arguments' => $decision->arguments, 'thought' => $decision->thought()]);

        $this->jobs->recordStep($job, $context->iteration, StepType::Action, null, [
            'tool' => $decision->tool, 'arguments' => $decision->arguments,
        ]);

        // 4a. Action guardrails can BLOCK this one action (dup / repeated fail).
        //     A block is fed back as an observation — the agent course-corrects.
        if ($verdict = $this->guardrails->inspectAction($context, $decision)) {
            $this->trace->record($job, EventType::GuardrailTriggered, $verdict->message, ['tool' => $decision->tool]);
            $this->memory->appendToolObservation($job, $decision->tool, $verdict->message);
            $this->jobs->recordStep($job, $context->iteration, StepType::Observation, $verdict->message);

            // A block is no progress. Count consecutive blocks and advance the
            // iteration so budget guardrails still bound the run — otherwise a
            // model that keeps choosing a blocked action loops forever (the exact
            // bug that hung the Iran job). After a short streak, stop for real.
            $this->jobs->incrementIteration($job);
            if ($this->bumpStall($job) >= (int) config('research.limits.max_stalls', 6)) {
                $this->finalizeByGuardrail($job, GuardrailVerdict::stop('stalled',
                    'Stopped: the agent kept choosing blocked actions and made no progress.'));

                return;
            }

            $this->reschedule($job);

            return;
        }

        // 4b. Execute (Laravel executes — never the LLM). Failures come back as
        //     ToolResult::fail(), not exceptions.
        $tool = $this->registry->get($decision->tool);
        $this->jobs->setActivity($job, 'tool:'.$tool->name());
        $result = $this->runner->run($tool, $decision->toolArguments(), $context);

        // 4c. Store the observation into memory + audit trail. The card in the UI
        //     shows only the first line; the FULL reasoning + observation go into
        //     the event payload so the detail popup can show everything untruncated.
        $this->trace->record($job, EventType::Observation,
            "Observation from {$tool->name()}: ".$this->firstLine($result->observation),
            [
                'tool' => $tool->name(),
                'success' => $result->success,
                'deferred' => $result->deferred,
                'thought' => $decision->thought(),
                'observation' => Str::limit($result->observation, 20000),
            ]);

        $this->memory->appendToolObservation($job, $tool->name(), $result->observation);
        $this->jobs->recordStep($job, $context->iteration, StepType::Observation, $result->observation);

        $this->jobs->incrementToolCalls($job);
        $this->jobs->incrementIteration($job);
        $this->resetStall($job);   // real work happened → clear the stall streak

        event(new ResearchAdvanced($job->id, $context->iteration));

        // 4d. REVIEWER FINISH. submit_review is a Reviewer's structured verdict AND
        //     its loop terminator, but it is still just a TOOL CALL — the model
        //     never gets an actual "finish" action (keeping control flow
        //     deterministic: the tool only RECORDS the verdict, the orchestrator
        //     decides to end the run). Reusing the normal completion path fires
        //     ResearchCompleted exactly like any other finished job, so
        //     ResumeSupervisorOnChildDone applies the verdict uniformly.
        if ($job->role === JobRole::Reviewer && $decision->tool === 'submit_review' && $result->success) {
            $this->finish($job, $context, new FinishDecision(
                report: $result->observation,
                confidence: is_array($job->review_verdict) ? ($job->review_verdict['confidence'] ?? null) : null,
                thought: $decision->thought(),
            ));

            return;
        }

        // 5a. PARK a supervisor when there is genuinely nothing to do RIGHT NOW but
        //     workers are still running: no task awaiting review, and no pending task
        //     whose dependencies are all met. It stays dormant until a worker finishes
        //     and ResumeSupervisorOnChildDone wakes it. If instead an independent task
        //     is ready, it keeps looping and can delegate it — so independent tasks run
        //     in parallel while dependent ones wait.
        if ($job->isSupervisor() && $job->supervisorShouldWait()) {
            $this->jobs->setActivity($job, 'awaiting_worker');

            return;
        }

        // 5a-bis. ANTI-LIVELOCK: a weak supervisor can keep calling review_task on a
        //     not-ready task (wrong target) while workers run — spinning with no
        //     progress. If a review just FAILED and workers are still in flight,
        //     PARK; a finishing worker re-wakes us (ResumeSupervisorOnChildDone),
        //     by which point the review target is unambiguous. Bounded: once the
        //     last worker finishes there are no in-flight tasks, so it can't park
        //     forever — it must then review the (now clearly awaiting) tasks.
        if ($job->isSupervisor() && ! $result->success && $decision->tool === 'review_task'
            && $job->tasks()->where('status', TaskStatus::InProgress)->exists()) {
            $this->jobs->setActivity($job, 'awaiting_worker');

            return;
        }

        if ($result->pauseLoop) {
            $this->jobs->setActivity($job, 'awaiting_worker');

            return;
        }

        // 5b. Otherwise continue the loop. Even a deferred human question continues —
        //     the agent is never blocked on a human.
        $this->reschedule($job);
    }

    private function finish(ResearchJob $job, ResearchContext $ctx, FinishDecision $decision): void
    {
        $this->jobs->setActivity($job, null);
        $this->jobs->recordStep($job, $ctx->iteration, StepType::Finish, $decision->thought(), $decision->toArray());
        $this->jobs->complete($job, $decision->report, $decision->confidence);

        $this->trace->record($job, EventType::Finished,
            'Research complete (confidence: '.($decision->confidence ?? 'n/a').')',
            ['confidence' => $decision->confidence, 'report' => $decision->report]);

        event(new ResearchCompleted($job->id));
    }

    /**
     * True when a supervisor has planned tasks but none can still make progress:
     * nothing in flight, nothing awaiting review, no ready pending task. Every task
     * is Done, Failed, or a Pending task transitively blocked by a failed dependency
     * (it can never become ready with no worker running). This is the deterministic
     * terminal condition — the point to assemble the report instead of asking the LLM.
     */
    private function supervisorAllSettled(ResearchJob $job): bool
    {
        $tasks = $job->tasks()->get();
        if ($tasks->isEmpty()) {
            return false;   // nothing planned yet — let planning happen
        }

        $done = $tasks->where('status', TaskStatus::Done)->pluck('seq')->map(fn ($s) => (int) $s)->all();

        // A task being Reviewing (a reviewer agent owns it right now) is in-flight
        // exactly like InProgress — never settled/finishable while one runs.
        $inFlight = $tasks->contains(fn ($t) => in_array($t->status, [TaskStatus::InProgress, TaskStatus::Reviewing], true));
        $reviewable = $tasks->contains(fn ($t) => $t->status === TaskStatus::AwaitingReview);
        $readyPending = $tasks->contains(fn ($t) => $t->status === TaskStatus::Pending && $t->isReady($done));

        // Require at least one task to have actually reached a terminal state. This
        // separates a genuine finish (work completed / a task failed and blocked its
        // dependents) from a PLANNING DEADLOCK where every task is Pending but none
        // is ready (a bad forward/circular depends_on) — the latter must not be
        // paraded as "finished" with zero work done; leave it to the normal flow.
        $anyTerminal = $tasks->contains(fn ($t) => in_array($t->status, [TaskStatus::Done, TaskStatus::Failed], true));

        return $anyTerminal && ! $inFlight && ! $reviewable && ! $readyPending;
    }

    /**
     * Complete a supervisor by assembling the report from its tasks — no LLM turn.
     * The deliverable already exists in the shared workspace (each task wrote its
     * outputs there); this narrates what was produced and flags anything that
     * failed, with a confidence proportional to how much completed cleanly.
     */
    private function finishSupervisorDeterministically(ResearchJob $job, ResearchContext $ctx): void
    {
        [$report, $confidence] = $this->assembleSupervisorReport($job);

        $this->trace->record($job, EventType::Thought,
            'All tasks are settled — assembling the final report deterministically (no LLM finish turn).',
            ['confidence' => $confidence]);

        $this->finish($job, $ctx, new FinishDecision($report, $confidence, 'All tasks settled; report assembled from task outputs.'));
    }

    /**
     * Build the final report text + a confidence from the task list.
     *
     * @return array{0:string, 1:float}
     */
    private function assembleSupervisorReport(ResearchJob $job): array
    {
        $tasks = $job->tasks()->orderBy('seq')->get();
        $doneCount = 0;

        $lines = ["# {$job->goal}", ''];
        foreach ($tasks as $t) {
            $status = $t->status->value;
            if ($t->status === TaskStatus::Done) {
                $doneCount++;
            }
            $paths = array_values(array_filter((array) ($t->outputs ?? [])));
            $lines[] = "## Task #{$t->seq}: {$t->title} — {$status}";
            if ($paths) {
                $lines[] = 'Deliverable(s): '.implode(', ', $paths);
            }
            if ($t->status === TaskStatus::Failed) {
                $lines[] = 'This task could not be completed: '.($t->failureReason() ?: 'the worker did not produce a usable result.');
            } elseif ($result = trim((string) $t->result)) {
                $lines[] = mb_strimwidth($result, 0, 4000, '…');
            }
            $lines[] = '';
        }

        $total = max(1, $tasks->count());
        $confidence = round($doneCount / $total, 2);

        if ($doneCount < $tasks->count()) {
            array_splice($lines, 2, 0, [
                '> Note: '.($tasks->count() - $doneCount)." of {$tasks->count()} task(s) did not complete (see below). The deliverable is best-effort.",
                '',
            ]);
        }

        return [trim(implode("\n", $lines)), $confidence];
    }

    private function finalizeByGuardrail(ResearchJob $job, GuardrailVerdict $stop): void
    {
        // AUTO-CONTINUE: a supervisor that still has open tasks tops up its budget
        // and keeps going, so a long project runs to a finished deliverable instead
        // of stopping half-done at an arbitrary limit (bounded by a safety ceiling).
        if ($this->autoExtendSupervisor($job, $stop)) {
            return;
        }

        $this->jobs->setActivity($job, null);
        $this->trace->record($job, EventType::GuardrailTriggered,
            "Stopping job: {$stop->message}", ['reason' => $stop->reason]);

        match ($stop->reason) {
            'cancelled' => $this->cancel($job),
            'timeout', 'max_iterations', 'max_tool_calls', 'stalled' => $this->forceFinalReport($job, $stop),
            default => $this->fail($job, $stop->message),
        };
    }

    /** Whether this supervisor still owes a one-time goal-comprehension pass. */
    private function needsComprehension(ResearchJob $job): bool
    {
        return $job->isSupervisor()
            && config('research.supervisor.comprehension', true)
            && empty($job->requirements)
            && $job->tasks()->count() === 0;
    }

    /**
     * Deterministic delegation: spawn a worker for EVERY ready pending task (deps
     * all Done). The orchestrator does this — not the model — so ready tasks always
     * start (independents in parallel), and the supervisor never has to "decide" to
     * delegate. Returns how many were started.
     */
    private function autoDelegateReadyTasks(ResearchJob $job): int
    {
        $tasks = $job->tasks()->get();
        $done = $tasks->where('status', TaskStatus::Done)->pluck('seq')->map(fn ($s) => (int) $s)->all();
        $cap = (int) config('research.supervisor.max_task_attempts', 3);
        $spawned = 0;

        foreach ($tasks as $task) {
            if ($task->status !== TaskStatus::Pending || ! $task->isReady($done)) {
                continue;
            }

            // DETERMINISTIC LOOP-BREAK: a weak worker can produce output the
            // supervisor keeps rejecting (revise → redo → revise …) indefinitely.
            // Once a task has been delegated `cap` times, stop re-doing it and
            // FORCE-ACCEPT the best-effort output (its files already exist in the
            // shared workspace) so the project can finish. The trace records it so
            // the final confidence/report can reflect that it wasn't fully verified.
            if ($task->attempts >= $cap) {
                // If every attempt ended in a WORKER FAILURE (crash / rate-limit /
                // infra error) there is no artifact to keep — mark it Failed so the
                // project finishes honestly rather than parading a broken task as
                // Done. A task that produced output but kept getting revised is still
                // force-accepted (best-effort) to break an endless revise loop.
                $onlyFailed = str_starts_with((string) $task->result, ResearchTask::WORKER_ERROR_PREFIX);

                if ($onlyFailed) {
                    $task->update(['status' => TaskStatus::Failed]);
                    $this->trace->record($job, EventType::GuardrailTriggered,
                        "Task #{$task->seq} \"{$task->title}\" marked FAILED after {$task->attempts} attempts — "
                        .'every worker errored (e.g. rate limit) and produced no artifact; giving up on it so the project can finish.',
                        ['task' => $task->seq, 'attempts' => $task->attempts, 'failed' => true]);

                    continue;
                }

                $task->update(['status' => TaskStatus::Done]);
                $this->trace->record($job, EventType::GuardrailTriggered,
                    "Task #{$task->seq} \"{$task->title}\" force-accepted after {$task->attempts} attempts — "
                    .'revision limit reached; kept best-effort output to break an endless revise loop.',
                    ['task' => $task->seq, 'attempts' => $task->attempts, 'forced' => true]);

                continue;
            }

            // Decide how THIS (re)assignment should be shaped before spawning:
            // route around an unavailable model, and — for a non-availability
            // failure — diagnose a corrective guideline for the fresh worker.
            $this->routeRetry($job, $task);

            $goal = $this->buildWorkerGoal($job, $task, $tasks);

            $worker = $this->start->spawnWorker($job, $task, $goal);
            $task->update([
                'status' => TaskStatus::InProgress,
                'child_job_id' => $worker->id,
                'attempts' => $task->attempts + 1,
            ]);
            $this->trace->record($job, EventType::Observation,
                "Delegated task #{$task->seq} \"{$task->title}\" to a worker sub-agent.",
                ['task' => $task->seq, 'worker_id' => $worker->id, 'auto' => true]);
            $spawned++;
        }

        return $spawned;
    }

    /**
     * Deterministic dispatch for every task AWAITING REVIEW — no LLM call. This
     * is the asymmetric hybrid gate:
     *
     *  1. A deterministic PRE-GATE (ArtifactChecks::verifyOutputs) that may only
     *     REJECT, never accept — it checks the one thing code can know for
     *     certain (a declared output file exists & is non-empty). A rejection
     *     revises the task immediately with NO reviewer spawned: it would be
     *     pure waste to burn a whole reviewer agent on a task that is provably
     *     incomplete.
     *  2. Otherwise (the pre-gate passes, or the task declares no output files)
     *     a per-task REVIEWER agent is spawned to judge the rich/subjective part
     *     no deterministic check can — UNLESS reviewing is disabled
     *     (research.supervisor.reviewer_enabled), in which case the task is left
     *     AwaitingReview for the supervisor's own review_task fallback (today's
     *     behaviour).
     *
     * Revising here shares the SAME attempt cap as a worker failure: neither
     * path increments `attempts` directly — only (re)delegation does (see
     * autoDelegateReadyTasks above) — so a task gets N total tries across BOTH
     * worker failures and review revisions, exactly like before this change.
     *
     * Returns how many tasks were moved out of AwaitingReview this turn, so the
     * caller knows whether to rebuild context before proceeding.
     */
    private function autoDispatchReviews(ResearchJob $job): int
    {
        $tasks = $job->tasks()->where('status', TaskStatus::AwaitingReview)->get();
        if ($tasks->isEmpty()) {
            return 0;
        }

        $reviewerEnabled = (bool) config('research.supervisor.reviewer_enabled', true);
        $workspace = $this->workspaceIdFor($job);
        $moved = 0;

        foreach ($tasks as $task) {
            $check = $this->artifactChecks->verifyOutputs($workspace, $task);

            if ($check['problems']) {
                $task->update([
                    'status' => TaskStatus::Pending,
                    'brief' => $task->brief."\n\nREVISION NEEDED — fix this: ".implode('; ', $check['problems']),
                ]);
                $this->trace->record($job, EventType::GuardrailTriggered,
                    "Pre-gate rejected task #{$task->seq} \"{$task->title}\" — a declared output is "
                    .'missing or empty; sent straight back to revise (no reviewer spawned).',
                    ['task' => $task->seq, 'problems' => $check['problems']]);
                $moved++;

                continue;
            }

            if (! $reviewerEnabled) {
                continue;   // leave AwaitingReview — the supervisor's own review_task judges it
            }

            // Route the reviewer around a throttled model too — mirror routeRetry
            // (workers) so a reviewer never lands on a model already known to be
            // in cooldown. The intended tier follows StartResearch::spawnWorker's
            // own resolution (reviewer_tier override, else the task's tier, else
            // default_tier) so this reasons about the SAME tier that would run.
            $reviewerTier = $this->reviewerIntendedTier($task);
            if ($picked = $this->pickAvailableTier($reviewerTier)) {
                $this->trace->record($job, EventType::GuardrailTriggered,
                    "Model \"{$picked['from']}\" is unavailable for reviewing task #{$task->seq} \"{$task->title}\" — "
                    ."spawning the reviewer on \"{$picked['model']}\" (tier {$picked['tier']}) instead.",
                    ['task' => $task->seq, 'from' => $picked['from'], 'to' => $picked['model']]);
                $reviewerTier = $picked['tier'];
            }

            $goal = $this->buildReviewerGoal($job, $task, $check);
            $reviewer = $this->start->spawnWorker($job, $task, $goal, JobRole::Reviewer, $reviewerTier);
            $task->update(['status' => TaskStatus::Reviewing]);

            $this->trace->record($job, EventType::Observation,
                "Task #{$task->seq} \"{$task->title}\" passed the deterministic pre-gate — spawned a "
                .'Reviewer agent to judge it.',
                ['task' => $task->seq, 'reviewer_id' => $reviewer->id, 'verified' => $check['verified']]);
            $moved++;
        }

        return $moved;
    }

    /**
     * Compose a REVIEWER's goal for ONE task: the brief, the declared output(s),
     * the deterministic pre-gate result AS ESTABLISHED FACTS (so the reviewer
     * spends its small, bounded budget on the rich/subjective judgment a
     * file-exists check cannot make), and the worker's own self-report to be
     * verified — not trusted.
     *
     * @param  array{problems: list<string>, verified: list<string>}  $check
     */
    private function buildReviewerGoal(ResearchJob $job, ResearchTask $task, array $check): string
    {
        $paths = array_values(array_filter((array) ($task->outputs ?? [])));
        $verified = $check['verified'] ?? [];

        $out = 'You are reviewing ONE finished task from a larger project. Verify it with your '
            ."tools — do NOT trust the worker's self-report below just because it claims success.\n\n"
            ."TASK #{$task->seq}: {$task->title}\n{$task->brief}\n\n"
            ."THE WORKER'S SELF-REPORT (unverified — check it, don't just believe it):\n"
            .trim((string) $task->result)."\n";

        if ($paths) {
            $out .= "\nDECLARED OUTPUT FILE(S) in the shared workspace: ".implode(', ', $paths).'.';
        }

        if ($verified) {
            $out .= "\n\nESTABLISHED FACTS (already checked deterministically before you were even "
                .'started — trust these, no need to re-verify pure existence): '.implode(', ', $verified).'.';
        }

        $out .= "\n\nThe overall project goal is:\n\"{$job->goal}\"\n\n"
            .'Use your tools to judge the part a deterministic check cannot: read the actual '
            .'file content, read_webpage a served URL if one is claimed reachable, run_command '
            .'to run tests or count required units (words/paragraphs/chapters/sections), and '
            .'look for leftover scaffolding (a raw JSON blob, "CURRENT STATE", template '
            .'placeholders like "chapter N goes here"). Then call submit_review with your '
            .'verdict (accept/revise), specific notes, and a confidence.';

        return $out;
    }

    /**
     * The tier a reviewer for this task would run on, absent any availability
     * rerouting — mirrors StartResearch::spawnWorker's own resolution (reviewer_tier
     * override, else the task's tier, else default_tier) so pickAvailableTier
     * reasons about the SAME tier that will actually be used.
     */
    private function reviewerIntendedTier(ResearchTask $task): string
    {
        $tier = trim((string) config('research.supervisor.reviewer_tier', ''));
        if ($tier === '') {
            $tier = (string) $task->tier;
        }

        return $tier !== '' ? $tier : (string) config('research.llm.default_tier', 'standard');
    }

    /** The sandbox workspace a job's tasks share (root's slug, else root id, else own id). */
    private function workspaceIdFor(ResearchJob $job): string
    {
        return $job->workspace_slug ?: ($job->root_job_id ?: $job->id);
    }

    /**
     * Compose a worker's goal for ONE task. Beyond the task itself, this hands the
     * worker two things earlier versions omitted — the reasons a "combine" worker
     * re-did research from scratch instead of using it:
     *
     *  - The project's extracted REQUIREMENTS (constraints/ordering), so the worker
     *    honors the same spec the supervisor planned against.
     *  - Its dependencies' ACTUAL OUTPUTS: each finished input task's declared file
     *    path(s) AND its result text, with a hard "these are DONE — use them, do NOT
     *    redo their work" directive. So an assembly/combine task reads its inputs
     *    instead of researching afresh.
     *
     * @param  Collection<int,ResearchTask>  $tasks  all of the supervisor's tasks
     */
    private function buildWorkerGoal(ResearchJob $job, ResearchTask $task, $tasks): string
    {
        $out = "TASK — your single objective:\n{$task->title}\n{$task->brief}\n";

        // A corrective guideline from FailureDiagnosis: a previous worker failed
        // this task and the supervisor decided a specific instruction will fix it.
        if ($guidance = trim((string) ($task->retry_guidance ?? ''))) {
            $out .= "\n⚠ A PREVIOUS ATTEMPT AT THIS TASK FAILED. Do this differently now: {$guidance}\n";
        }

        $writes = array_values(array_filter((array) ($task->outputs ?? [])));
        if ($writes) {
            $out .= "\nWRITE your deliverable to this exact path in the shared workspace: "
                .implode(', ', $writes).' (use write_file).';
        }

        if ($req = $this->requirementsBrief($job)) {
            $out .= "\n\n{$req}";
        }

        // Hand over each dependency's real output so the worker builds ON it.
        $deps = array_values(array_filter((array) ($task->depends_on ?? [])));
        $inputs = [];
        foreach ($deps as $depSeq) {
            $dep = $tasks->firstWhere('seq', (int) $depSeq);
            if (! $dep) {
                continue;
            }
            $paths = array_values(array_filter((array) ($dep->outputs ?? [])));
            $head = "• Task #{$dep->seq} \"{$dep->title}\"".($paths ? ' → wrote: '.implode(', ', $paths) : '');
            $result = trim((string) $dep->result);
            if ($result !== '') {
                $head .= "\n  Its result:\n  ".str_replace("\n", "\n  ", mb_strimwidth($result, 0, 4000, '…'));
            }
            $inputs[] = $head;
        }

        if ($inputs) {
            $out .= "\n\nINPUTS ALREADY PRODUCED — these dependency tasks are DONE. Their output is "
                .'below and their files are in your shared workspace (list_files / read_file to open '
                .'them). USE this material — combine, assemble, or build on it. Do NOT search the web '
                ."or redo research/work that is already provided here:\n".implode("\n", $inputs);
        }

        $out .= "\n\nThis is ONE part of a larger project. The overall project goal is:\n\"{$job->goal}\"\n\n"
            .'You SHARE the workspace with the other sub-agents. Do ONLY this task. Finish with a '
            .'report that IS the deliverable (the actual content / result / answer), not a description of it.';

        return $out;
    }

    /** A compact, worker-facing view of the project's extracted requirements. */
    private function requirementsBrief(ResearchJob $job): string
    {
        $req = $job->requirements ?? [];
        if (empty($req)) {
            return '';
        }

        $lines = [];
        foreach ((array) ($req['constraints'] ?? []) as $c) {
            $lines[] = "- {$c}";
        }
        if (empty($lines)) {
            return '';
        }

        return "PROJECT CONSTRAINTS you must respect:\n".implode("\n", $lines);
    }

    /**
     * Shape a (re)assignment before the worker spawns. Two deterministic-first steps:
     *
     *  1. AVAILABILITY (a fact, no LLM). If the task's model is in cooldown — or this
     *     retry just failed BECAUSE the model was unavailable (rate-limit / gateway) —
     *     hand the task to an available model. This is "analyse the failed job's data,
     *     see the model is down, hand it to another model", done in code.
     *  2. APPROACH (bounded LLM). Only for a non-availability failure: one
     *     FailureDiagnosis turn decides whether to add a corrective guideline to the
     *     next worker (stored on the task) or just retry clean.
     *
     * Never abandons — the attempt cap (handled by the caller) is the only give-up.
     */
    private function routeRetry(ResearchJob $job, ResearchTask $task): void
    {
        $failed = $task->lastAttemptFailed();
        $reason = $task->failureReason();
        $availabilityFail = $failed && $this->availability->isAvailabilityFailure($reason);

        $tier = $task->tier ?: (string) config('research.llm.default_tier', 'standard');

        if ($picked = $this->pickAvailableTier($tier, $availabilityFail)) {
            $task->update(['tier' => $picked['tier'], 'retry_guidance' => null]);
            $this->trace->record($job, EventType::GuardrailTriggered,
                "Model \"{$picked['from']}\" is unavailable for task #{$task->seq} \"{$task->title}\" — handed it to \"{$picked['model']}\" (tier {$picked['tier']}).",
                ['task' => $task->seq, 'from' => $picked['from'], 'to' => $picked['model'], 'reason' => $reason]);

            return;   // availability handled — do not also diagnose approach
        }
        // No alternative is up (or nothing needed rerouting) — fall through to a
        // clean retry / diagnosis on the tier's own model.

        if ($failed && ! $availabilityFail && config('research.supervisor.diagnose_failures', true)) {
            $cap = (int) config('research.supervisor.max_task_attempts', 3);
            $d = $this->diagnosis->diagnose($job, $task, $reason, $task->attempts + 1, $cap);
            $task->update(['retry_guidance' => $d['decision'] === 'retry_with_guidance' ? $d['guidance'] : null]);

            return;
        }

        // First attempt (or an availability retry with nothing free): no guideline.
        if ($task->retry_guidance) {
            $task->update(['retry_guidance' => null]);
        }
    }

    /**
     * Given an INTENDED tier, decide whether to route AROUND its model — shared
     * by routeRetry (workers) and autoDispatchReviews (reviewers) so both spawn
     * paths avoid a throttled model the same deterministic way.
     *
     * Reroutes when $forceReroute is true (the caller already knows the LAST
     * attempt on this tier failed for availability) OR the tier's own model is
     * independently in cooldown (e.g. a PREVIOUS reviewer on it just failed).
     * Returns null when no reroute is needed, or when one is needed but nothing
     * else is available — either way the caller keeps its intended tier/model;
     * this never blocks a spawn on availability.
     *
     * @return array{tier:string,model:string,from:string}|null
     */
    private function pickAvailableTier(string $intendedTier, bool $forceReroute = false): ?array
    {
        $model = $this->resolveTierModel($intendedTier);
        if ($model === '' || ! ($forceReroute || $this->availability->inCooldown($model))) {
            return null;
        }

        $candidates = $this->tierModelsExcept($intendedTier);
        $replacement = $this->availability->pickReplacement(array_values($candidates), $model);
        if ($replacement === null) {
            return null;   // nothing else is up — best effort, retry/spawn on the same model
        }

        $newTier = (string) (array_search($replacement, $candidates, true) ?: $intendedTier);

        return ['tier' => $newTier, 'model' => $replacement, 'from' => $model];
    }

    /** Resolve a tier to its concrete model, falling back to the global model when blank. */
    private function resolveTierModel(string $tier): string
    {
        $m = trim((string) config("research.llm.tiers.$tier.model", ''));

        return $m !== '' ? $m : trim((string) config('research.llm.model', ''));
    }

    /**
     * Every OTHER tier mapped to its resolved model, in config order — the ordered
     * candidate pool for handing a task off to an available model.
     *
     * @return array<string,string> tierName => model
     */
    private function tierModelsExcept(string $exclude): array
    {
        $out = [];
        foreach ((array) config('research.llm.tiers', []) as $name => $_) {
            if ($name === $exclude) {
                continue;
            }
            $model = $this->resolveTierModel((string) $name);
            if ($model !== '') {
                $out[(string) $name] = $model;
            }
        }

        return $out;
    }

    private function bumpStall(ResearchJob $job): int
    {
        $n = (int) Cache::get("research:stall:{$job->id}", 0) + 1;
        Cache::put("research:stall:{$job->id}", $n, 3600);

        return $n;
    }

    private function resetStall(ResearchJob $job): void
    {
        Cache::forget("research:stall:{$job->id}");
    }

    /**
     * If a supervisor hit an iteration/tool/time limit but still has open tasks
     * and is under the safety ceiling, top up its budget and keep going. Returns
     * true if it extended (caller should stop finalizing).
     */
    private function autoExtendSupervisor(ResearchJob $job, GuardrailVerdict $stop): bool
    {
        if (! $job->isSupervisor() || ! in_array($stop->reason, ['max_iterations', 'max_tool_calls', 'timeout'], true)) {
            return false;
        }

        $openTasks = $job->tasks()->whereIn('status', ['pending', 'in_progress', 'awaiting_review', 'reviewing'])->exists();
        $ceiling = (int) config('research.supervisor.max_iterations_ceiling', 400);
        if (! $openTasks || $job->iteration >= $ceiling) {
            return false;   // nothing left to do, or hit the hard ceiling → really stop
        }

        $add = (int) config('research.supervisor.auto_extend_iterations', 30);
        $this->jobs->extendBudget($job, $add, $add, (int) config('research.limits.timeout_seconds', 3600));

        $this->trace->record($job, EventType::Resumed,
            "Budget topped up (+{$add}) — tasks still open, continuing the project.",
            ['reason' => $stop->reason]);

        $this->reschedule($job);

        return true;
    }

    /** On a limit/timeout, produce a best-effort report rather than nothing. */
    private function forceFinalReport(ResearchJob $job, GuardrailVerdict $stop): void
    {
        $report = $this->planner->summarizeBestEffort($this->buildContext($job), $stop->message);
        $this->jobs->complete($job, $report, confidence: null, partial: true);

        $this->trace->record($job, EventType::Finished,
            "Produced a PARTIAL report ({$stop->reason}).",
            ['reason' => $stop->reason, 'report' => $report], null);

        event(new ResearchCompleted($job->id, partial: true));
    }

    private function cancel(ResearchJob $job): void
    {
        $this->jobs->markCancelled($job);
        $this->trace->record($job, EventType::Cancelled, 'Job cancelled by supervisor.');
    }

    private function fail(ResearchJob $job, string $reason): void
    {
        $this->jobs->fail($job, $reason);
        $this->trace->record($job, EventType::Failed, "Job failed: {$reason}");
        event(new ResearchFailed($job->id, $reason));
    }

    /** The LLM broke the contract — nudge it, and fail the job if it persists. */
    private function handleInvalidDecision(ResearchJob $job, InvalidDecisionException $e): void
    {
        $count = $this->jobs->incrementParseFailures($job);
        $max = (int) config('research.limits.max_parse_failures', 3);

        $this->trace->record($job, EventType::InvalidLlmResponse,
            "Invalid LLM response ({$count}/{$max}): {$e->getMessage()}", ['error' => $e->getMessage()]);

        if ($count >= $max) {
            $this->fail($job, "LLM repeatedly returned invalid responses: {$e->getMessage()}");

            return;
        }

        // Feed the error back so the model can fix its next response.
        $this->memory->appendToolObservation($job, 'system',
            "Your last response was rejected: {$e->getMessage()} "
            .'Respond again with a SINGLE valid JSON object matching the contract.');

        $this->reschedule($job);
    }

    private function reschedule(ResearchJob $job): void
    {
        // Between iterations we're waiting for a worker to pick up the next turn.
        $this->jobs->setActivity($job, 'queued');
        AdvanceResearchJob::dispatch($job->id)->onQueue(config('research.queue.name'));
    }

    private function buildContext(ResearchJob $job): ResearchContext
    {
        $openQuestions = collect($this->humanQuestions->openFor($job->id))
            ->map(fn ($q) => ['id' => $q->id, 'question' => $q->question, 'status' => $q->status->value])
            ->all();

        // Supervisors see their live task list every turn (re-anchored, so no drift);
        // their tool catalogue is the control tools only.
        $tasks = $job->isSupervisor()
            ? $job->tasks()->get()->map(fn ($t) => [
                'seq' => $t->seq, 'title' => $t->title, 'brief' => $t->brief,
                'status' => $t->status->value, 'depends_on' => $t->depends_on ?? [],
            ])->all()
            : [];

        return new ResearchContext(
            job: $job,
            iteration: $job->iteration,
            goal: $job->goal,
            messages: $this->memory->transcript($job),
            toolDefs: $this->registry->definitions($job->config['allowed_tools'] ?? null, $job->role),
            recentTools: $this->jobs->recentToolExecutions($job, 20),
            openHumanQuestions: $openQuestions,
            humanStatusSummary: $this->humans->summary(),
            role: $job->role,
            tasks: $tasks,
        );
    }

    private function firstLine(string $s): string
    {
        $line = trim(strtok($s, "\n") ?: $s);

        return mb_strlen($line) > 160 ? mb_substr($line, 0, 160).'…' : $line;
    }
}
