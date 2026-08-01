<?php

namespace App\Listeners;

use App\Domain\Research\Contracts\HumanQuestionRepository;
use App\Domain\Research\Contracts\MemoryRepository;
use App\Domain\Research\Contracts\ResearchJobRepository;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
use App\Events\HumanQuestionAnswered;

/**
 * When a human answers, inject the answer into the job's memory (and timeline).
 * The planner loop never blocks on a human, so if the job is still running it
 * picks the answer up on its next iteration; if it already finished, this is a
 * no-op.
 */
class ResumeResearchOnHumanAnswer
{
    public function __construct(
        private HumanQuestionRepository $questions,
        private ResearchJobRepository $jobs,
        private MemoryRepository $memory,
        private TraceRecorder $trace,
    ) {}

    public function handle(HumanQuestionAnswered $event): void
    {
        $q = $this->questions->find($event->questionId);
        $job = $this->jobs->find($q->research_job_id);

        $this->memory->appendHumanAnswer(
            $job,
            "HUMAN ANSWER to \"{$q->question}\": {$q->answer}",
        );

        $this->trace->record($job, EventType::HumanAnswerReceived,
            "Human answered \"{$q->question}\": ".$this->clip((string) $q->answer),
            ['question_id' => $q->id, 'answer' => $q->answer]);
    }

    private function clip(string $s): string
    {
        return mb_strlen($s) > 120 ? mb_substr($s, 0, 120).'…' : $s;
    }
}
