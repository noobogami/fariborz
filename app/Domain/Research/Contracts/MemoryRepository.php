<?php

namespace App\Domain\Research\Contracts;

use App\Models\ResearchJob;

interface MemoryRepository
{
    /** Seed a brand-new job's memory with the initial goal message. */
    public function seedGoal(ResearchJob $job): void;

    /** Append the assistant's own decision (its "assistant" turn). */
    public function appendAssistant(ResearchJob $job, string $content, array $meta = []): void;

    /** Append a tool observation (role "tool"). */
    public function appendToolObservation(ResearchJob $job, string $toolName, string $observation): void;

    /** Append a human's answer (role "human"). */
    public function appendHumanAnswer(ResearchJob $job, string $content): void;

    /**
     * The transcript to replay to the LLM, windowed/summarized to fit the
     * token budget.
     *
     * @return array<int, array{role:string, content:string}>
     */
    public function transcript(ResearchJob $job): array;
}
