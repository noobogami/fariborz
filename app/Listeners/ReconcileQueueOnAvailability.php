<?php

namespace App\Listeners;

use App\Application\Research\Human\QuestionQueueManager;
use App\Domain\Research\Enums\HumanStatus;
use App\Domain\Research\Enums\JobStatus;
use App\Events\HumanAvailabilityChanged;
use App\Models\ResearchJob;

/**
 * When a human comes online, re-evaluate the outstanding question queue for
 * every active job: drop what's no longer needed, dedupe, prioritise, batch,
 * and ask only the highest-value questions.
 */
class ReconcileQueueOnAvailability
{
    public function __construct(private QuestionQueueManager $queue) {}

    public function handle(HumanAvailabilityChanged $event): void
    {
        if ($event->newStatus !== HumanStatus::Available->value) {
            return;
        }

        ResearchJob::where('status', JobStatus::Running->value)
            ->each(fn (ResearchJob $job) => $this->queue->reconcile($job));
    }
}
