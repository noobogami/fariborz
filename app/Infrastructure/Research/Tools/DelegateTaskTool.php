<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\StartResearch;
use App\Domain\Research\Contracts\ControlTool;
use App\Domain\Research\Enums\JobRole;
use App\Domain\Research\Enums\TaskStatus;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use App\Models\ResearchTask;

/**
 * SUPERVISOR tool. Hand ONE task to a fresh worker sub-agent. The worker runs
 * its own focused loop and reports back; this supervisor job PAUSES until it
 * does (the loop resumes automatically on the worker's completion).
 */
class DelegateTaskTool implements ControlTool
{
    public function __construct(private StartResearch $start) {}

    public function name(): string
    {
        return 'delegate_task';
    }

    public function description(): string
    {
        return 'Delegate one ready task (by its number) to a new sub-agent, which does the '
            .'actual research/writing/building and reports back. mode="worker" (default) does '
            .'the task directly; mode="project" spawns a SUB-SUPERVISOR that breaks a big task '
            .'down further into its own tasks — use it when a task is too large for one worker '
            .'to do well in a single run. You may delegate several READY independent tasks to '
            .'run in parallel; dependent tasks are blocked until their dependencies are verified.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The task number (from the task list) to delegate.'],
                'mode' => ['type' => 'string', 'enum' => ['worker', 'project'], 'description' => 'worker = do it directly (default); project = spawn a sub-supervisor to break it down further.'],
                'extra_context' => ['type' => 'string', 'description' => 'Optional extra guidance/handoff for the sub-agent (findings so far, constraints).'],
            ],
            'required' => ['task'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        $seq = $args->int('task');
        $task = ResearchTask::where('research_job_id', $ctx->jobId())->where('seq', $seq)->first();

        if (! $task) {
            return ToolResult::fail("There is no task #{$seq} in the plan.");
        }
        if (! in_array($task->status, [TaskStatus::Pending, TaskStatus::Failed], true)) {
            return ToolResult::fail("Task #{$seq} is already {$task->status->value}; delegate a pending task instead.");
        }

        // DEPENDENCY GATE — a task may only start once every task it depends on is
        // VERIFIED (Done). Enforced in code, not just the prompt, so a weak model
        // can't start chapter 4 while chapter 3 is only awaiting review. Independent
        // tasks (empty depends_on) are free to run in parallel with others.
        $all = ResearchTask::where('research_job_id', $ctx->jobId())->get();
        $done = $all->where('status', TaskStatus::Done)->pluck('seq')->map(fn ($s) => (int) $s)->all();

        $blocking = collect($task->depends_on ?? [])->reject(fn ($d) => in_array((int) $d, $done, true));
        if ($blocking->isNotEmpty()) {
            $detail = $blocking->map(function ($d) use ($all) {
                $dep = $all->firstWhere('seq', (int) $d);

                return "#{$d}".($dep ? " ({$dep->status->value})" : '');
            })->implode(', ');

            return ToolResult::fail(
                "Cannot start task #{$seq} yet — it depends on {$detail}, which "
                .(count($blocking) === 1 ? 'is' : 'are').' not verified/Done yet. Finish and '
                .'review the dependency first (a task that only AWAITS REVIEW is not done — review_task it).'
            );
        }

        $mode = $args->string('mode', 'worker') === 'project' ? JobRole::Supervisor : JobRole::Worker;
        // Cap recursion: past the max depth, a sub-project becomes a plain worker.
        if ($mode === JobRole::Supervisor && $ctx->job->depth() >= (int) config('research.supervisor.max_depth', 3)) {
            $mode = JobRole::Worker;
        }
        $extra = trim($args->string('extra_context'));
        $goal = "TASK — your single objective:\n{$task->title}\n{$task->brief}\n\n"
            .($extra !== '' ? "Context from the supervisor:\n{$extra}\n\n" : '')
            ."This is ONE part of a larger project. The overall project goal is:\n\"{$ctx->goal}\"\n\n"
            .'You SHARE the project workspace with the other sub-agents — read what they wrote '
            ."(list_files/read_file) and write your output into agreed files.\n"
            .($mode === JobRole::Supervisor
                ? 'This task is large: break it into your own smaller tasks and delegate them.'
                : 'Do ONLY this task. Finish with a report that IS the deliverable for the task '
                    .'(the actual content/result/answer), not a description of it.');

        $worker = $this->start->spawnWorker($ctx->job, $task, $goal, $mode);

        $task->update([
            'status' => TaskStatus::InProgress,
            'child_job_id' => $worker->id,
            'attempts' => $task->attempts + 1,
        ]);

        return ToolResult::ok(
            "Delegated task #{$seq} \"{$task->title}\" to a worker sub-agent (it is now RUNNING). "
            .'You may delegate other tasks whose dependencies are met to run in parallel, or wait '
            .'for a worker to report and review it. You will be woken when a worker finishes.',
            ['task' => $seq, 'worker_id' => $worker->id]
        );
    }
}
