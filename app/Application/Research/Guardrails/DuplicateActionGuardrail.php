<?php

namespace App\Application\Research\Guardrails;

use App\Domain\Research\Contracts\Guardrail;
use App\Domain\Research\ValueObjects\Decision;
use App\Domain\Research\ValueObjects\GuardrailVerdict;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolCall;
use App\Models\ToolExecution;

/**
 * Stops the agent repeating an identical tool call it already made. The
 * fingerprint is order-insensitive and case-normalised, so "Company X revenue"
 * and "company x  revenue" collide. ask_human is exempt (the same question may
 * legitimately be re-raised as circumstances change).
 */
class DuplicateActionGuardrail implements Guardrail
{
    public function phase(): string
    {
        return 'action';
    }

    /** Supervisor control tools legitimately repeat (re-delegate a revised task, etc.). */
    private const EXEMPT = ['ask_human', 'plan_tasks', 'delegate_task', 'review_task'];

    public function evaluate(ResearchContext $ctx, ?Decision $decision): GuardrailVerdict
    {
        // A supervisor legitimately repeats actions (re-read a file to verify,
        // re-delegate a revised task). Its own control tools guard against real
        // duplication; the search-dedup this guardrail does is for workers.
        if ($ctx->isSupervisor()) {
            return GuardrailVerdict::pass();
        }

        if (! $decision instanceof ToolCall || in_array($decision->tool, self::EXEMPT, true)) {
            return GuardrailVerdict::pass();
        }

        $fingerprint = ToolExecution::fingerprint($decision->tool, $decision->arguments);

        $already = collect($ctx->recentTools)
            ->firstWhere('fingerprint', $fingerprint);

        if ($already && ($already['status'] ?? null) === 'success') {
            return GuardrailVerdict::block(
                "You already ran {$decision->tool} with those exact arguments and got a result "
                .'earlier in this conversation. Do not repeat it — re-read that observation, or '
                .'choose a different query/tool, or finish if you have enough evidence.'
            );
        }

        return GuardrailVerdict::pass();
    }
}
