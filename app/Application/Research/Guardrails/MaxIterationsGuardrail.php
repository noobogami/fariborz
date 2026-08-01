<?php

namespace App\Application\Research\Guardrails;

use App\Domain\Research\Contracts\Guardrail;
use App\Domain\Research\ValueObjects\Decision;
use App\Domain\Research\ValueObjects\GuardrailVerdict;
use App\Domain\Research\ValueObjects\ResearchContext;

class MaxIterationsGuardrail implements Guardrail
{
    public function phase(): string
    {
        return 'preflight';
    }

    public function evaluate(ResearchContext $ctx, ?Decision $decision): GuardrailVerdict
    {
        $max = $ctx->job->limit('max_iterations');

        if ($ctx->iteration >= $max) {
            return GuardrailVerdict::stop('max_iterations', "Reached the maximum of {$max} iterations.");
        }

        return GuardrailVerdict::pass();
    }
}
