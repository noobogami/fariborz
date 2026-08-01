<?php

namespace App\Domain\Research\Contracts;

use App\Domain\Research\Enums\JobRole;
use App\Domain\Research\Enums\StepType;
use App\Models\ResearchJob;

interface ResearchJobRepository
{
    public function find(string $id): ResearchJob;

    public function create(string $goal, array $config, JobRole $role = JobRole::Solo, ?string $parentId = null): ResearchJob;

    public function markCancelled(ResearchJob $job): void;

    /** Re-open a finished job to continue it (new config = topped-up budget). */
    public function continueRun(ResearchJob $job, array $config): void;

    /** Top up a running supervisor's iteration/tool budget + wall-clock deadline. */
    public function extendBudget(ResearchJob $job, int $addIterations, int $addToolCalls, int $addSeconds): void;

    public function complete(ResearchJob $job, string $report, ?float $confidence, bool $partial = false): void;

    public function fail(ResearchJob $job, string $error): void;

    /** Update the live "what is it doing right now" phase (null = no active phase). */
    public function setActivity(ResearchJob $job, ?string $activity): void;

    public function incrementIteration(ResearchJob $job): void;

    public function incrementToolCalls(ResearchJob $job): void;

    public function incrementParseFailures(ResearchJob $job): int;

    public function resetParseFailures(ResearchJob $job): void;

    public function recordStep(ResearchJob $job, int $iteration, StepType $type, ?string $thought = null, ?array $action = null): void;

    /** @return array recent tool_executions as plain arrays (for guardrails) */
    public function recentToolExecutions(ResearchJob $job, int $limit = 20): array;

    /** Called from the failed() hook when a whole iteration crashes. */
    public function markIterationCrashed(string $jobId, string $error): void;
}
