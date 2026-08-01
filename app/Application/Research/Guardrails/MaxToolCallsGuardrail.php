<?php

namespace App\Application\Research\Guardrails;

use App\Domain\Research\Contracts\Guardrail;
use App\Domain\Research\ValueObjects\Decision;
use App\Domain\Research\ValueObjects\GuardrailVerdict;
use App\Domain\Research\ValueObjects\ResearchContext;

class MaxToolCallsGuardrail implements Guardrail
{
    public function phase(): string
    {
        return 'preflight';
    }

    public function evaluate(ResearchContext $ctx, ?Decision $decision): GuardrailVerdict
    {
        $max = $ctx->job->limit('max_tool_calls');

        if ($ctx->job->tool_call_count >= $max) {
            return GuardrailVerdict::stop('max_tool_calls', "Reached the maximum of {$max} tool calls.");
        }

        return GuardrailVerdict::pass();
    }
}
