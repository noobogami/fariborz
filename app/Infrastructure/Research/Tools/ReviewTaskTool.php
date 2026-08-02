<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
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
 *
 * Accept is NOT a rubber-stamp: before a task can be marked Done, the tool checks
 * that the task's DECLARED OUTPUT FILES actually exist in the shared workspace
 * with real content. A weak supervisor tends to accept a worker's self-report
 * ("UI deployed successfully") without ever reading the artifact — this guard
 * turns that claim into a fact that must be true on disk, or accept is refused.
 */
class ReviewTaskTool implements ControlTool
{
    /** Trimmed content shorter than this (chars) counts as "essentially empty". */
    private const MIN_CONTENT_CHARS = 30;

    public function __construct(private SandboxClient $sandbox) {}

    public function name(): string
    {
        return 'review_task';
    }

    public function description(): string
    {
        return "Review a finished worker's result for a task and decide: 'accept' if it truly "
            ."satisfies the task's brief, or 'revise' (with notes) to send it back to a fresh "
            .'worker. Accept is verified against the real files in the workspace — read the '
            .'artifact yourself before accepting. This keeps the final result aligned.';
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
            // Point the model at the RIGHT target instead of a bare rejection — a
            // weak supervisor otherwise re-tries the same wrong task (e.g. one
            // already Done) every turn and livelocks while workers run.
            $awaiting = ResearchTask::where('research_job_id', $ctx->jobId())
                ->where('status', TaskStatus::AwaitingReview)->orderBy('seq')->pluck('seq')->all();
            $guide = $awaiting
                ? 'Review '.(count($awaiting) === 1 ? "task #{$awaiting[0]}" : 'tasks '.implode(', ', array_map(fn ($s) => "#$s", $awaiting)))
                    .' instead — only ★ REVIEW tasks can be reviewed.'
                : 'No task is awaiting review right now; wait for the running workers to finish.';

            return ToolResult::fail("Task #{$seq} is {$task->status->value}, not awaiting review. {$guide}");
        }

        if ($args->string('verdict') === 'accept') {
            // Acceptance check: the declared deliverables must really exist with
            // real content. This is the automated gate that replaces "trust the
            // worker's self-report".
            $verdict = $this->verifyOutputs($ctx->workspaceId(), $task);

            if ($verdict['problems']) {
                return ToolResult::fail(
                    "Cannot accept task #{$seq} \"{$task->title}\" — its deliverable is not really there:\n"
                    .'- '.implode("\n- ", $verdict['problems'])."\n"
                    .'Do NOT accept a worker\'s self-report. Either wait for the worker to finish, or '
                    ."'revise' with notes telling the next worker exactly what to produce and where.",
                    ['task' => $seq, 'verdict' => 'accept_blocked', 'problems' => $verdict['problems']]
                );
            }

            $task->update(['status' => TaskStatus::Done]);

            $checked = $verdict['verified']
                ? ' Verified deliverable(s): '.implode(', ', $verdict['verified']).'.'
                : '';

            return ToolResult::ok(
                "Accepted task #{$seq} \"{$task->title}\" as done.{$checked} Move to the next pending "
                .'task, or finish if every task is done.',
                ['task' => $seq, 'verdict' => 'accept', 'verified' => $verdict['verified']]
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

    /**
     * Check each declared output path exists in the workspace with real content.
     *
     * Returns ['problems' => string[], 'verified' => string[]]. `problems` is
     * empty when there is nothing blocking acceptance. The check FAILS CLOSED on
     * real evidence (a 404 / empty file is a genuine problem) but FAILS OPEN on
     * infrastructure trouble (sandbox unreachable) so a sandbox outage can never
     * wedge every review — we simply can't verify, so we don't block.
     *
     * @return array{problems: list<string>, verified: list<string>}
     */
    private function verifyOutputs(string $workspace, ResearchTask $task): array
    {
        $paths = array_values(array_filter(
            array_map('trim', (array) ($task->outputs ?? [])),
            fn ($p) => is_string($p) && $p !== '' && $p !== '...'
                // Skip directory markers and glob placeholders — we can only
                // meaningfully read concrete files back.
                && ! str_ends_with($p, '/') && ! str_contains($p, '*'),
        ));

        // No concrete file deliverable declared (e.g. a pure research task) —
        // nothing to verify, so don't stand in the way of acceptance.
        if (! $paths) {
            return ['problems' => [], 'verified' => []];
        }

        $problems = [];
        $verified = [];

        foreach ($paths as $path) {
            try {
                $r = $this->sandbox->read($workspace, $path);
            } catch (SandboxException $e) {
                // The sandbox reachably reported the file isn't there (404) —
                // that's real evidence the deliverable is missing.
                $problems[] = "\"{$path}\" was not found in the workspace (worker never wrote it).";

                continue;
            } catch (\Throwable $e) {
                // Sandbox unreachable / transport error — cannot verify. Fail open:
                // abandon the whole check rather than block review on infra trouble.
                return ['problems' => [], 'verified' => []];
            }

            $content = trim((string) ($r['content'] ?? ''));
            if ($content === '') {
                $problems[] = "\"{$path}\" exists but is empty.";
            } elseif (mb_strlen($content) < self::MIN_CONTENT_CHARS) {
                $problems[] = "\"{$path}\" is essentially empty (".mb_strlen($content).' chars).';
            } else {
                $verified[] = $path.' ('.mb_strlen($content).' chars)';
            }
        }

        return ['problems' => $problems, 'verified' => $verified];
    }
}
