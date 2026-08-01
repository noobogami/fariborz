<?php

namespace App\Application\Research;

use App\Domain\Research\Contracts\MemoryRepository;
use App\Domain\Research\Contracts\ResearchJobRepository;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
use App\Domain\Research\Enums\JobRole;
use App\Jobs\AdvanceResearchJob;
use App\Models\ResearchJob;
use App\Models\ResearchTask;

/**
 * The single entry point for kicking off a research run: create the job, seed
 * its memory with the goal, record the opening timeline event, and dispatch
 * the first iteration. Used by both the HTTP controller and the CLI command.
 */
class StartResearch
{
    public function __construct(
        private ResearchJobRepository $jobs,
        private MemoryRepository $memory,
        private TraceRecorder $trace,
    ) {}

    /**
     * @param  array<string,mixed>  $overrides  per-job config overrides (limits, allowed_tools…)
     */
    public function handle(string $goal, array $overrides = [], JobRole $role = JobRole::Solo): ResearchJob
    {
        $config = array_replace_recursive([
            'limits' => config('research.limits'),
            'allowed_tools' => null, // null = all registered tools
        ], $overrides);

        $job = $this->jobs->create($goal, $config, $role);
        $this->memory->seedGoal($job);

        $this->trace->record($job, EventType::JobStarted,
            ($role === JobRole::Supervisor ? 'Supervised project started for goal: ' : 'Research started for goal: ').$goal,
            ['config' => $config, 'role' => $role->value]);

        AdvanceResearchJob::dispatch($job->id)->onQueue(config('research.queue.name'));

        return $job;
    }

    /**
     * Spawn a sub-agent for ONE task of a supervisor's plan and return it. In
     * WORKER mode it does the task directly (short budget). In SUPERVISOR mode it
     * is a SUB-PROJECT that decomposes the task further into its own task list —
     * this is how a big task is "broken down more" so even a weak model can do the
     * atomic pieces. Either way its final report becomes the task result.
     */
    public function spawnWorker(ResearchJob $parent, ResearchTask $task, string $goal, JobRole $role = JobRole::Worker): ResearchJob
    {
        $isProject = $role === JobRole::Supervisor;

        $config = [
            'limits' => array_replace(config('research.limits'), $isProject ? [] : [
                'max_iterations' => (int) config('research.supervisor.worker_max_iterations', 20),
                'max_tool_calls' => (int) config('research.supervisor.worker_max_tool_calls', 25),
            ]),
            'allowed_tools' => null,
        ];

        $child = $this->jobs->create($goal, $config, $role, $parent->id);
        $this->memory->seedGoal($child);

        $this->trace->record($child, EventType::JobStarted,
            ($isProject ? 'Sub-project started for task #' : 'Worker started for task #').$task->seq.": {$task->title}",
            ['task_id' => $task->id, 'parent_job_id' => $parent->id, 'role' => $role->value]);

        AdvanceResearchJob::dispatch($child->id)->onQueue(config('research.queue.name'));

        return $child;
    }
}
