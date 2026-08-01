<?php

namespace App\Application\Research\Guardrails;

use App\Domain\Research\Contracts\Guardrail;
use App\Domain\Research\ValueObjects\Decision;
use App\Domain\Research\ValueObjects\GuardrailVerdict;
use App\Domain\Research\ValueObjects\ResearchContext;

/**
 * Runs guardrails in two phases and returns the first non-passing verdict.
 * Open/closed: add a Guardrail to the tag list and it participates — the
 * orchestrator never changes.
 */
class GuardrailPipeline
{
    /** @var array<int, Guardrail> */
    private array $preflight = [];

    /** @var array<int, Guardrail> */
    private array $action = [];

    /** @param iterable<Guardrail> $guardrails */
    public function __construct(iterable $guardrails)
    {
        foreach ($guardrails as $g) {
            match ($g->phase()) {
                'action' => $this->action[] = $g,
                default => $this->preflight[] = $g,
            };
        }
    }

    /** @return GuardrailVerdict|null a terminal verdict, or null to proceed */
    public function preflight(ResearchContext $ctx): ?GuardrailVerdict
    {
        foreach ($this->preflight as $g) {
            $verdict = $g->evaluate($ctx, null);
            if ($verdict->isStop()) {
                return $verdict;
            }
        }

        return null;
    }

    /** @return GuardrailVerdict|null a blocking verdict, or null to proceed */
    public function inspectAction(ResearchContext $ctx, Decision $decision): ?GuardrailVerdict
    {
        foreach ($this->action as $g) {
            $verdict = $g->evaluate($ctx, $decision);
            if ($verdict->isBlock()) {
                return $verdict;
            }
        }

        return null;
    }
}
