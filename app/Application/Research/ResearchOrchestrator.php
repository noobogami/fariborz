<?php

namespace App\Application\Research;

use App\Application\Research\Guardrails\GuardrailPipeline;
use App\Application\Research\Human\HumanAvailabilityService;
use App\Application\Research\Planner\InvalidDecisionException;
use App\Application\Research\Tools\ToolRegistry;
use App\Application\Research\Tools\ToolRunner;
use App\Domain\Research\Contracts\HumanQuestionRepository;
use App\Domain\Research\Contracts\MemoryRepository;
use App\Domain\Research\Contracts\Planner;
use App\Domain\Research\Contracts\ResearchJobRepository;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
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

        // 1b. SUPERVISOR = deterministic control flow ("blueprint first, model
        //     second"). The ORCHESTRATOR — not the weak model — delegates every
        //     ready task and decides when to wait. The model is only asked for the
        //     bounded steps it's good at: plan, review ONE task, or assemble/finish.
        //     This is what killed the delegate→blocked→delegate thrash loop.
        if ($job->isSupervisor()) {
            if ($this->autoDelegateReadyTasks($job) > 0) {
                $context = $this->buildContext($job);   // ready tasks are now running
            }
            if ($job->supervisorShouldWait()) {
                $this->jobs->setActivity($job, 'awaiting_worker');   // wait for workers — no LLM call, no loop

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
        $spawned = 0;

        foreach ($tasks as $task) {
            if ($task->status !== TaskStatus::Pending || ! $task->isReady($done)) {
                continue;
            }

            $goal = "TASK — your single objective:\n{$task->title}\n{$task->brief}\n\n"
                ."This is ONE part of a larger project. The overall project goal is:\n\"{$job->goal}\"\n\n"
                .'You SHARE the project workspace with the other sub-agents — read what they wrote '
                ."(list_files / read_file) and write your output into the file path(s) the task names.\n"
                .'Do ONLY this task. Finish with a report that IS the deliverable (the actual '
                .'content / result / answer), not a description of it.';

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

        $openTasks = $job->tasks()->whereIn('status', ['pending', 'in_progress', 'awaiting_review'])->exists();
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
