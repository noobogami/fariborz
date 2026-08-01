<?php

namespace App\Domain\Research\Contracts;

use App\Domain\Research\ValueObjects\Decision;
use App\Domain\Research\ValueObjects\GuardrailVerdict;
use App\Domain\Research\ValueObjects\ResearchContext;

interface Guardrail
{
    /**
     * 'preflight' guardrails run before the LLM is consulted and can STOP the
     * whole job (limits, timeout, cancellation).
     * 'action' guardrails run after the LLM chooses a tool and can BLOCK that
     * single action (duplicate, repeated failure).
     */
    public function phase(): string; // 'preflight' | 'action'

    public function evaluate(ResearchContext $context, ?Decision $decision): GuardrailVerdict;
}
