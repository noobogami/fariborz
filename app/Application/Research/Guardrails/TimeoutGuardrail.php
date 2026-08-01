<?php

namespace App\Application\Research\Guardrails;

use App\Domain\Research\Contracts\Guardrail;
use App\Domain\Research\ValueObjects\Decision;
use App\Domain\Research\ValueObjects\GuardrailVerdict;
use App\Domain\Research\ValueObjects\ResearchContext;

class TimeoutGuardrail implements Guardrail
{
    public function phase(): string
    {
        return 'preflight';
    }

    public function evaluate(ResearchContext $ctx, ?Decision $decision): GuardrailVerdict
    {
        $deadline = $ctx->job->deadline_at;

        if ($deadline !== null && now()->greaterThan($deadline)) {
            return GuardrailVerdict::stop('timeout', "Exceeded the wall-clock deadline ({$deadline}).");
        }

        return GuardrailVerdict::pass();
    }
}
