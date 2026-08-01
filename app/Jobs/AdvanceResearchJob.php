<?php

namespace App\Jobs;

use App\Application\Research\ResearchOrchestrator;
use App\Application\Settings\SettingsService;
use App\Domain\Research\Contracts\ResearchJobRepository;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
use App\Events\ResearchFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One iteration of the planner loop. Re-dispatched by the orchestrator until
 * the job finishes, so the queue itself IS the loop.
 *
 * tries = 1 deliberately: we do NOT want the queue to blindly replay a whole
 * iteration (that could double-run a side-effecting tool). Retries are handled
 * semantically inside the ToolRunner / LLM client instead.
 */
class AdvanceResearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Per-iteration wall clock. MUST stay below the queue connection's
    // retry_after (config/queue.php, 660s) or a long iteration (e.g. a sandbox
    // build) gets re-dispatched and fails with "attempted too many times".
    public int $timeout = 600;

    public function __construct(public string $jobId) {}

    public function handle(): void
    {
        // Heartbeat: proof a worker is actually consuming the research queue.
        // The dashboard reads this to tell "working" apart from "queue is down".
        Cache::put('research:worker:last_seen', time(), 300);

        // Re-apply UI settings each iteration so edits (model, keys, limits…) take
        // effect live on the long-running worker. The orchestrator is resolved
        // AFTER this so it (and its ToolRegistry) reflect the current settings.
        app(SettingsService::class)->apply();

        app(ResearchOrchestrator::class)->advance($this->jobId);
    }

    /** Uniquely tag the job for easier tracing in Horizon/failed_jobs. */
    public function tags(): array
    {
        return ['research', "job:{$this->jobId}"];
    }

    public function failed(Throwable $e): void
    {
        // The iteration blew up (e.g. the LLM was unreachable). Surface it on the
        // timeline AND mark the job failed so it stops looking "running" forever.
        $repo = app(ResearchJobRepository::class);

        try {
            $job = $repo->find($this->jobId);
            app(TraceRecorder::class)->record(
                $job,
                EventType::Failed,
                'Iteration crashed: '.$e->getMessage(),
                ['exception' => $e::class],
            );
        } catch (Throwable) {
            // Even if tracing fails, still mark the job failed below.
        }

        $repo->markIterationCrashed($this->jobId, $e->getMessage());
        event(new ResearchFailed($this->jobId, $e->getMessage()));
    }
}
