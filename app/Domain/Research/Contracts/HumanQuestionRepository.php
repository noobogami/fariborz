<?php

namespace App\Domain\Research\Contracts;

use App\Models\Human;
use App\Models\HumanQuestion;

interface HumanQuestionRepository
{
    public function create(string $jobId, string $question, array $tags, int $priority, ?string $toolExecutionId = null): HumanQuestion;

    public function markQueued(HumanQuestion $q): void;

    public function assign(HumanQuestion $q, Human $human, string $status): void;

    public function markObsolete(HumanQuestion $q, string $resolvedSource): void;

    public function recordAnswer(HumanQuestion $q, string $answer, string $source, ?float $confidence = null): void;

    public function find(string $id): HumanQuestion;

    /** The most recent question for this job whose text matches (normalized), or null. */
    public function existingFor(string $jobId, string $question): ?HumanQuestion;

    /** @return array<int, HumanQuestion> open (queued|asked) questions for a job */
    public function openFor(string $jobId): array;
}
