<?php

namespace App\Domain\Research\Contracts;

use App\Domain\Research\ValueObjects\Decision;
use App\Domain\Research\ValueObjects\ResearchContext;

interface Planner
{
    /** Ask the brain for the single next action. */
    public function decide(ResearchContext $context): Decision;

    /**
     * Forced best-effort final report when a limit/timeout is hit — so a job
     * never returns nothing.
     */
    public function summarizeBestEffort(ResearchContext $context, string $reason): string;
}
