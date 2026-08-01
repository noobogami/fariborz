<?php

namespace App\Infrastructure\Research\Persistence;

use App\Domain\Research\Contracts\MemoryRepository;
use App\Models\ResearchJob;
use App\Models\ResearchMessage;

class EloquentMemoryRepository implements MemoryRepository
{
    public function seedGoal(ResearchJob $job): void
    {
        $this->append($job, 'human', "RESEARCH GOAL:\n{$job->goal}");
    }

    public function appendAssistant(ResearchJob $job, string $content, array $meta = []): void
    {
        $this->append($job, 'assistant', $content, $meta);
    }

    public function appendToolObservation(ResearchJob $job, string $toolName, string $observation): void
    {
        $this->append($job, 'tool', "[{$toolName}] {$observation}", ['tool' => $toolName]);
    }

    public function appendHumanAnswer(ResearchJob $job, string $content): void
    {
        $this->append($job, 'human', $content, ['kind' => 'human_answer']);
    }

    public function transcript(ResearchJob $job): array
    {
        $window = (int) config('research.llm.transcript_window', 40);

        $messages = $job->messages()->get(['role', 'content', 'sequence']);

        // If we're within the window, replay verbatim.
        if ($messages->count() <= $window) {
            return $messages->map(fn ($m) => [
                'role' => $this->mapRole($m->role),
                'content' => $m->content,
            ])->all();
        }

        // Otherwise: summarize the older messages into one block and keep the
        // most recent `window` verbatim. (A vector-store impl can replace this.)
        $older = $messages->slice(0, $messages->count() - $window);
        $recent = $messages->slice($messages->count() - $window);

        $summary = "SUMMARY OF EARLIER PROGRESS ({$older->count()} earlier messages):\n"
            .$older->map(fn ($m) => '- '.$this->truncate($m->content, 200))->implode("\n");

        return array_merge(
            [['role' => 'user', 'content' => $summary]],
            $recent->map(fn ($m) => [
                'role' => $this->mapRole($m->role),
                'content' => $m->content,
            ])->all(),
        );
    }

    private function append(ResearchJob $job, string $role, string $content, array $meta = []): void
    {
        $next = (int) $job->messages()->max('sequence') + 1;

        ResearchMessage::create([
            'research_job_id' => $job->id,
            'role' => $role,
            'content' => $content,
            'meta' => $meta ?: null,
            'sequence' => $next,
        ]);
    }

    /** Map our internal roles onto the two roles the chat API accepts. */
    private function mapRole(string $role): string
    {
        return $role === 'assistant' ? 'assistant' : 'user';
    }

    private function truncate(string $s, int $len): string
    {
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len).'…' : $s;
    }
}
