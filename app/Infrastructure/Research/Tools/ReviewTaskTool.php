<?php

namespace App\Infrastructure\Research\Tools;

use App\Domain\Research\Contracts\ControlTool;
use App\Domain\Research\Enums\TaskStatus;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use App\Models\ResearchTask;

/**
 * SUPERVISOR tool. Verify a finished worker's result against the task's brief.
 * Accept it (task done) or send it back to be re-done with specific notes. This
 * is the quality gate that keeps the deliverable aligned to the goal.
 */
class ReviewTaskTool implements ControlTool
{
    public function name(): string
    {
        return 'review_task';
    }

    public function description(): string
    {
        return "Review a finished worker's result for a task and decide: 'accept' if it truly "
            ."satisfies the task's brief, or 'revise' (with notes) to send it back to a fresh "
            .'worker. Review each task before moving on — this keeps the final result aligned.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The task number to review.'],
                'verdict' => ['type' => 'string', 'enum' => ['accept', 'revise'], 'description' => 'accept = done; revise = re-do it.'],
                'notes' => ['type' => 'string', 'description' => 'For "revise": exactly what was wrong / what to fix. Recorded for the next worker.'],
            ],
            'required' => ['task', 'verdict'],
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
        if ($task->status !== TaskStatus::AwaitingReview) {
            return ToolResult::fail("Task #{$seq} is {$task->status->value}, not awaiting review. Delegate or wait for it first.");
        }

        if ($args->string('verdict') === 'accept') {
            $task->update(['status' => TaskStatus::Done]);

            return ToolResult::ok(
                "Accepted task #{$seq} \"{$task->title}\" as done. Move to the next pending task, "
                .'or finish if every task is done.',
                ['task' => $seq, 'verdict' => 'accept']
            );
        }

        // Revise: reopen with the reviewer's notes appended for the next worker.
        $notes = trim($args->string('notes'));
        $task->update([
            'status' => TaskStatus::Pending,
            'brief' => $task->brief."\n\nREVISION NEEDED — fix this: ".($notes !== '' ? $notes : 'the result did not satisfy the task.'),
        ]);

        return ToolResult::ok(
            "Sent task #{$seq} back to be re-done. delegate_task it again to run a fresh worker with your notes.",
            ['task' => $seq, 'verdict' => 'revise']
        );
    }
}
