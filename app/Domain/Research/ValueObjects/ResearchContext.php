<?php

namespace App\Domain\Research\ValueObjects;

use App\Domain\Research\Enums\JobRole;
use App\Models\ResearchJob;

/**
 * An immutable snapshot of everything the planner + guardrails need for one
 * turn. Built fresh each iteration from the database, so it is always an
 * accurate reflection of persisted state (this is what makes resume safe).
 */
final class ResearchContext
{
    public function __construct(
        public readonly ResearchJob $job,
        public readonly int $iteration,
        public readonly string $goal,
        /** @var array<int, array{role:string, content:string}> chat transcript */
        public readonly array $messages,
        /** @var array tool definitions available to this job */
        public readonly array $toolDefs,
        /** @var array recent tool_executions for dedup/failure guardrails */
        public readonly array $recentTools,
        /** @var array open human questions for the prompt */
        public readonly array $openHumanQuestions = [],
        public readonly string $humanStatusSummary = 'unknown',
        public readonly JobRole $role = JobRole::Solo,
        /** @var array<int, array{seq:int, title:string, brief:string, status:string}> supervisor plan */
        public readonly array $tasks = [],
    ) {}

    public function jobId(): string
    {
        return $this->job->id;
    }

    /**
     * The sandbox workspace this job builds in. The WHOLE project tree shares the
     * root's workspace, so files a worker writes are visible to sibling workers
     * and a served app can be assembled + deployed in one place.
     */
    public function workspaceId(): string
    {
        return $this->job->root_job_id ?: $this->job->id;
    }

    public function isSupervisor(): bool
    {
        return $this->role === JobRole::Supervisor;
    }
}
