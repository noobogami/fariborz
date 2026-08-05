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
     * atomic pieces. In REVIEWER mode it JUDGES the task instead of doing it — a
     * small, bounded budget, since verifying is cheaper than producing. Either
     * way its outcome (report, or verdict for a reviewer) is applied to the task.
     *
     * @param  ?string  $tierOverride  Explicit tier to pin this sub-agent to,
     *                                 bypassing the normal tier resolution below.
     *                                 Used by ResearchOrchestrator::autoDispatchReviews
     *                                 to route a reviewer around a model it already
     *                                 knows is in cooldown — see pickAvailableTier().
     */
    public function spawnWorker(ResearchJob $parent, ResearchTask $task, string $goal, JobRole $role = JobRole::Worker, ?string $tierOverride = null): ResearchJob
    {
        $isProject = $role === JobRole::Supervisor;
        $isReviewer = $role === JobRole::Reviewer;

        $config = [
            'limits' => array_replace(config('research.limits'), match (true) {
                $isProject => [],
                $isReviewer => [
                    'max_iterations' => (int) config('research.supervisor.reviewer_max_iterations', 8),
                    'max_tool_calls' => (int) config('research.supervisor.reviewer_max_iterations', 8),
                ],
                default => [
                    'max_iterations' => (int) config('research.supervisor.worker_max_iterations', 20),
                    'max_tool_calls' => (int) config('research.supervisor.worker_max_tool_calls', 25),
                ],
            }),
            'allowed_tools' => null,
        ];

        // Carry a capability tier so this sub-agent runs on the right model
        // (per-task routing; see ModelRouter). A reviewer uses its OWN configured
        // tier override when set, else the task's tier; a worker/sub-project
        // always uses the task's tier. Absent = falls back to
        // research.llm.default_tier. An explicit $tierOverride (reviewers only)
        // wins over all of that — the caller already resolved the tier and may
        // have rerouted it around a model in cooldown.
        $tier = $isReviewer
            ? (($tierOverride !== null && $tierOverride !== '') ? $tierOverride
                : (trim((string) config('research.supervisor.reviewer_tier', '')) ?: (string) $task->tier))
            : (string) $task->tier;
        if ($tier !== '') {
            $config['tier'] = $tier;
        }

        // A reviewer must know WHICH task it is judging — workers are found via
        // research_tasks.child_job_id (the task points at them), but a task's
        // child_job_id keeps pointing at its WORKER through review, so a reviewer
        // is instead found via its own config.
        if ($isReviewer) {
            $config['review_task_id'] = $task->id;
        }

        $child = $this->jobs->create($goal, $config, $role, $parent->id);
        $this->memory->seedGoal($child);

        $label = match (true) {
            $isProject => 'Sub-project started for task #',
            $isReviewer => 'Reviewer started for task #',
            default => 'Worker started for task #',
        };
        $this->trace->record($child, EventType::JobStarted,
            $label.$task->seq.": {$task->title}",
            ['task_id' => $task->id, 'parent_job_id' => $parent->id, 'role' => $role->value]);

        AdvanceResearchJob::dispatch($child->id)->onQueue(config('research.queue.name'));

        return $child;
    }
}
