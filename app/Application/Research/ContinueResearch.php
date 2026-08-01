<?php

namespace App\Application\Research;

use App\Domain\Research\Contracts\MemoryRepository;
use App\Domain\Research\Contracts\ResearchJobRepository;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
use App\Jobs\AdvanceResearchJob;
use App\Models\ResearchJob;

/**
 * Continue a FINISHED research job with new guidance instead of starting over.
 * The job's entire memory is intact, so appending a supervisor instruction and
 * resuming lets the agent refine its result while keeping everything it learned.
 */
class ContinueResearch
{
    public function __construct(
        private ResearchJobRepository $jobs,
        private MemoryRepository $memory,
        private TraceRecorder $trace,
    ) {}

    public function handle(string $jobId, string $guidance): ResearchJob
    {
        $job = $this->jobs->find($jobId);

        // Inject the new guidance as the latest turn the agent will read. A
        // SUPERVISOR must turn follow-up work into TASKS and delegate them (it has
        // no hands of its own) — telling it to just "finish" is why "now deploy it"
        // used to no-op. A solo/worker job addresses it directly and re-finishes.
        $followUp = $job->isSupervisor()
            ? "FOLLOW-UP FROM THE HUMAN: {$guidance}\n\n"
                .'This is NEW work on the SAME project. Do not just finish. Add the necessary '
                .'task(s) with plan_tasks and delegate them to workers (they share the project '
                .'workspace and can run commands / start servers). Only finish once the new work '
                .'is actually done and verified.'
            : "FOLLOW-UP FROM THE HUMAN: {$guidance}\n\n"
                .'Continue from your existing findings — do not start over. Address this '
                .'guidance and produce an updated final report (finish) when done.';

        $this->memory->appendHumanAnswer($job, $followUp);

        // Top up the budget from where it stopped so guardrails don't halt it
        // immediately, and refresh the wall-clock deadline.
        $config = $job->config;
        $config['limits']['max_iterations'] = $job->iteration + 20;
        $config['limits']['max_tool_calls'] = $job->tool_call_count + 30;

        $this->jobs->continueRun($job, $config);

        $this->trace->record($job, EventType::Resumed,
            'Continued with new guidance: '.$this->clip($guidance),
            ['guidance' => $guidance]);

        AdvanceResearchJob::dispatch($job->id)->onQueue(config('research.queue.name'));

        return $job;
    }

    private function clip(string $s): string
    {
        return mb_strlen($s) > 140 ? mb_substr($s, 0, 140).'…' : $s;
    }
}
