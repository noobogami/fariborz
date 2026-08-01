<?php

namespace App\Application\Research\Guardrails;

use App\Domain\Research\Contracts\Guardrail;
use App\Domain\Research\ValueObjects\Decision;
use App\Domain\Research\ValueObjects\GuardrailVerdict;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolCall;

/**
 * If a tool has failed repeatedly, stop letting the agent bang on it and nudge
 * it toward a different approach. Prevents a broken integration from eating the
 * whole iteration budget.
 */
class RepeatedFailureGuardrail implements Guardrail
{
    public function phase(): string
    {
        return 'action';
    }

    public function evaluate(ResearchContext $ctx, ?Decision $decision): GuardrailVerdict
    {
        // Not for supervisors: their control tools (review/plan) legitimately
        // repeat, and a task that "fails" for unmet-dependency reasons is control
        // feedback, not a broken tool — penalising it poisoned delegation and
        // caused an infinite block loop.
        if (! $decision instanceof ToolCall || $ctx->isSupervisor()) {
            return GuardrailVerdict::pass();
        }

        $max = (int) ($ctx->job->config['limits']['max_tool_failures']
            ?? config('research.limits.max_tool_failures', 3));

        $failures = collect($ctx->recentTools)
            ->where('tool_name', $decision->tool)
            ->where('status', 'failed')
            ->count();

        if ($failures >= $max) {
            return GuardrailVerdict::block(
                "The tool {$decision->tool} has failed {$failures} times recently. Stop using it "
                .'and try a different tool or approach to get this information.'
            );
        }

        return GuardrailVerdict::pass();
    }
}
