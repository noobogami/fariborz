<?php

namespace App\Jobs;

use App\Application\Research\Human\QuestionQueueManager;
use App\Domain\Research\Enums\JobStatus;
use App\Models\ResearchJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Periodically re-evaluates every active job's human-question queue so that
 * questions which became answerable elsewhere are dropped and priorities stay
 * fresh. Scheduled in the console kernel.
 */
class ReevaluateHumanQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(QuestionQueueManager $queue): void
    {
        ResearchJob::where('status', JobStatus::Running->value)
            ->each(fn (ResearchJob $job) => $queue->reconcile($job));
    }
}
