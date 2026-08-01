<?php

namespace App\Infrastructure\Research\Persistence;

use App\Domain\Research\Contracts\HumanQuestionRepository;
use App\Domain\Research\Enums\QuestionStatus;
use App\Models\Human;
use App\Models\HumanQuestion;

class EloquentHumanQuestionRepository implements HumanQuestionRepository
{
    public function create(string $jobId, string $question, array $tags, int $priority, ?string $toolExecutionId = null): HumanQuestion
    {
        return HumanQuestion::create([
            'research_job_id' => $jobId,
            'tool_execution_id' => $toolExecutionId,
            'question' => $question,
            'tags' => $tags ?: null,
            'priority' => $priority,
            'status' => QuestionStatus::Queued,
        ]);
    }

    public function markQueued(HumanQuestion $q): void
    {
        $q->update(['status' => QuestionStatus::Queued]);
    }

    public function assign(HumanQuestion $q, Human $human, string $status): void
    {
        $q->update([
            'assigned_human_id' => $human->id,
            'status' => $status,
            'asked_at' => now(),
        ]);
    }

    public function markObsolete(HumanQuestion $q, string $resolvedSource): void
    {
        $q->update([
            'status' => QuestionStatus::Obsolete,
            'resolved_source' => $resolvedSource,
        ]);
    }

    public function recordAnswer(HumanQuestion $q, string $answer, string $source, ?float $confidence = null): void
    {
        $q->update([
            'status' => $source === 'human' ? QuestionStatus::Answered : QuestionStatus::ResolvedByOther,
            'answer' => $answer,
            'resolved_source' => $source,
            'resolved_confidence' => $confidence,
            'answered_at' => now(),
        ]);
    }

    public function find(string $id): HumanQuestion
    {
        return HumanQuestion::findOrFail($id);
    }

    public function existingFor(string $jobId, string $question): ?HumanQuestion
    {
        $norm = HumanQuestion::normalize($question);

        // Per-job question counts are small; normalize + compare in PHP keeps
        // this dependency-free (no extra hash column/migration).
        return HumanQuestion::where('research_job_id', $jobId)
            ->latest()
            ->get()
            ->first(fn (HumanQuestion $q) => HumanQuestion::normalize($q->question) === $norm);
    }

    public function openFor(string $jobId): array
    {
        return HumanQuestion::query()
            ->where('research_job_id', $jobId)
            ->whereIn('status', [QuestionStatus::Queued->value, QuestionStatus::Asked->value])
            ->orderByDesc('priority')
            ->get()
            ->all();
    }
}
