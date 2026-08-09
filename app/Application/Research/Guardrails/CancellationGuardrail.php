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
 *
 * Also stops a sub-agent whose SUPERVISOR (at any depth) was cancelled. The
 * cascade in CancelResearch already cancels every sub-agent that existed at the
 * time, so this covers the race only: an in-flight supervisor iteration that
 * spawned a worker just after the cascade ran. Stopping a job must stop its
 * workers — including the ones born a moment too late.
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
        $job = $ctx->job->fresh();

        if ($job->status === JobStatus::Cancelled) {
            return GuardrailVerdict::stop('cancelled', 'The job was cancelled by a supervisor.');
        }

        if ($job->hasCancelledAncestor()) {
            return GuardrailVerdict::stop('cancelled', 'The parent job was cancelled — stopping this sub-agent.');
        }

        return GuardrailVerdict::pass();
    }
}
