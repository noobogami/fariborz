<?php

namespace App\Infrastructure\Research\Tools;

use App\Domain\Research\Contracts\ControlTool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;

/**
 * REVIEWER-only tool. A per-task Reviewer agent's ONLY way to end its run: it
 * judges ONE finished task against its brief and records a structured verdict
 * (accept/revise + notes + confidence) on ITS OWN job row (research_jobs.
 * review_verdict). This is deliberately NOT a "finish" — the reviewer's
 * deliverable is a VERDICT, not an artifact or a free-text report. This tool
 * only RECORDS that verdict; the ORCHESTRATOR is what ends the reviewer's loop
 * and APPLIES the verdict to the task (via ResumeSupervisorOnChildDone), which
 * keeps control flow deterministic — "blueprint first, model second" — exactly
 * like every other control tool in this system.
 */
class SubmitReviewTool implements ControlTool
{
    public function name(): string
    {
        return 'submit_review';
    }

    public function description(): string
    {
        return 'Record your verdict on the ONE task you were asked to review, and END your run '
            .'— this is your only way to finish, there is no separate "finish" action. "accept" '
            .'only if you VERIFIED with your tools that it truly satisfies the brief (real file '
            .'content, a served URL that actually responds, tests that actually pass); "revise" '
            .'with SPECIFIC notes if it does not. Do not accept a self-report.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => ['accept', 'revise'], 'description' => 'accept = the task is genuinely done; revise = send it back to be re-done.'],
                'notes' => ['type' => 'string', 'description' => 'What you checked and found. For "revise": exactly what is wrong / what to fix next time.'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'description' => 'How confident you are in this verdict, 0.0-1.0.'],
            ],
            'required' => ['verdict', 'notes'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        $verdict = $args->string('verdict');
        $notes = trim($args->string('notes'));

        if (! in_array($verdict, ['accept', 'revise'], true)) {
            return ToolResult::fail('"verdict" must be "accept" or "revise".');
        }

        $rawConfidence = $args->all()['confidence'] ?? null;
        $confidence = is_numeric($rawConfidence) ? max(0.0, min(1.0, (float) $rawConfidence)) : null;

        $ctx->job->update(['review_verdict' => [
            'verdict' => $verdict,
            'notes' => $notes,
            'confidence' => $confidence,
        ]]);

        return ToolResult::ok(
            "Verdict recorded: {$verdict}.".($notes !== '' ? " {$notes}" : ''),
            ['verdict' => $verdict, 'notes' => $notes, 'confidence' => $confidence]
        );
    }
}
