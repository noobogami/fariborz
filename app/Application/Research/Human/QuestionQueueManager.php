<?php

namespace App\Application\Research\Human;

use App\Domain\Research\Contracts\HumanQuestionRepository;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
use App\Events\HumanQuestionAsked;
use App\Models\HumanQuestion;
use App\Models\ResearchJob;

/**
 * Implements the human-queue policy from the spec:
 *
 *  While a human is unavailable, questions accumulate here and the agent keeps
 *  working. This manager is invoked (a) on a schedule and (b) when a human
 *  comes online. It then:
 *    1. drops questions already answered elsewhere / no longer needed,
 *    2. dedupes + batches related questions,
 *    3. reprioritises by how much each unblocks the research,
 *    4. asks only the highest-value few to the newly-available human.
 */
class QuestionQueueManager
{
    public function __construct(
        private HumanQuestionRepository $questions,
        private HumanAvailabilityService $availability,
        private TraceRecorder $trace,
    ) {}

    public function reconcile(ResearchJob $job): void
    {
        $open = $this->questions->openFor($job->id);

        if (empty($open)) {
            return;
        }

        // 1. Prune questions that are no longer needed.
        $open = array_values(array_filter($open, function (HumanQuestion $q) use ($job) {
            if ($this->answeredElsewhere($q)) {
                $this->questions->markObsolete($q, resolvedSource: $q->resolved_source ?? 'other_source');
                $this->trace->record($job, EventType::QuestionResolved,
                    "Dropped queued question (answered elsewhere): \"{$q->question}\"",
                    ['question_id' => $q->id]);

                return false;
            }

            return true;
        }));

        if (empty($open)) {
            return;
        }

        // 2 & 3. Dedupe + rank by unblock value (priority is our proxy signal here).
        $ranked = $this->rankByUnblockValue($this->dedupe($open));

        // 4. Ask only the top N to whoever is available, batching related ones.
        $limit = (int) config('research.human.max_batch', 3);
        $toAsk = array_slice($ranked, 0, $limit);
        $asked = 0;

        foreach ($toAsk as $q) {
            $responder = $this->availability->pickResponder($q->tags ?? []);
            if ($responder === null) {
                break; // nobody available anymore
            }
            $this->questions->assign($q, $responder, status: 'asked');
            event(new HumanQuestionAsked($q->id, $responder->id));
            $asked++;
        }

        $this->trace->record($job, EventType::QueueReconciled,
            'Reconciled human queue: '.count($open)." open, {$asked} asked, ".(count($ranked) - $asked).' still queued',
            ['open' => count($open), 'asked' => $asked]);
    }

    /**
     * Has another tool already produced a confident answer to this question?
     * Heuristic: look for a recent successful tool observation in the job's
     * memory that references the question. In practice you'd let the planner
     * mark it resolved, or run a small similarity check — kept simple here.
     */
    private function answeredElsewhere(HumanQuestion $q): bool
    {
        return $q->resolved_confidence !== null
            && $q->resolved_confidence >= (float) config('research.human.auto_resolve_confidence', 0.75);
    }

    /** @param array<int,HumanQuestion> $questions */
    private function dedupe(array $questions): array
    {
        $seen = [];
        $out = [];
        foreach ($questions as $q) {
            $key = mb_strtolower(trim($q->question));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $q;
        }

        return $out;
    }

    /** @param array<int,HumanQuestion> $questions */
    private function rankByUnblockValue(array $questions): array
    {
        usort($questions, fn (HumanQuestion $a, HumanQuestion $b) => $b->priority <=> $a->priority);

        return $questions;
    }
}
