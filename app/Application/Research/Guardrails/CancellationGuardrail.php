<?php

namespace App\Application\Research\Guardrails;

use App\Domain\Research\Contracts\Guardrail;
use App\Domain\Research\Enums\JobStatus;
use App\Domain\Research\ValueObjects\Decision;
use App\Domain\Research\ValueObjects\GuardrailVerdict;
use App\Domain\Research\ValueObjects\ResearchContext;

/**
 * A supervisor can cancel a job at any time. We re-read the status fresh so a
 * cancellation issued while an iteration was queued is honored immediately.
 */
class CancellationGuardrail implements Guardrail
{
    public function phase(): string
    {
        return 'preflight';
    }

    public function evaluate(ResearchContext $ctx, ?Decision $decision): GuardrailVerdict
    {
        // Re-read from the DB so a cancellation issued out-of-band (while this
        // iteration was queued) is honored immediately. fresh() reloads all columns.
        if ($ctx->job->fresh()->status === JobStatus::Cancelled) {
            return GuardrailVerdict::stop('cancelled', 'The job was cancelled by a supervisor.');
        }

        return GuardrailVerdict::pass();
    }
}
