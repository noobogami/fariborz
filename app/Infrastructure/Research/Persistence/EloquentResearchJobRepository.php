<?php

namespace App\Infrastructure\Research\Persistence;

use App\Domain\Research\Contracts\ResearchJobRepository;
use App\Domain\Research\Enums\JobRole;
use App\Domain\Research\Enums\JobStatus;
use App\Domain\Research\Enums\StepType;
use App\Models\ResearchJob;
use Illuminate\Support\Facades\DB;

class EloquentResearchJobRepository implements ResearchJobRepository
{
    public function find(string $id): ResearchJob
    {
        // Accept either the UUID (internal/foreign keys) or the human-readable
        // slug (URLs, sandbox), so both resolve the same job.
        return ResearchJob::where('id', $id)->orWhere('slug', $id)->firstOrFail();
    }

    public function create(string $goal, array $config, JobRole $role = JobRole::Solo, ?string $parentId = null): ResearchJob
    {
        $limits = $config['limits'] ?? config('research.limits');

        $slug = ResearchJob::generateSlug($goal);

        // The whole project tree shares ONE sandbox workspace, named by the ROOT
        // job's slug so the folder on disk is legible. Children inherit both the
        // root id and the root's workspace_slug from their parent.
        $root = null;
        $workspaceSlug = $slug; // a top-level job's workspace is its own slug
        if ($parentId) {
            $parent = ResearchJob::find($parentId);
            $root = $parent?->root_job_id ?? $parentId;
            $workspaceSlug = $parent?->workspace_slug ?: $slug;
        }

        $job = ResearchJob::create([
            'goal' => $goal,
            'slug' => $slug,
            'role' => $role,
            'parent_job_id' => $parentId,
            'root_job_id' => $root,
            'workspace_slug' => $workspaceSlug,
            'status' => JobStatus::Running,
            'config' => $config,
            'started_at' => now(),
            // Cast: env-derived config values arrive as strings; Carbon needs an int.
            'deadline_at' => now()->addSeconds((int) ($limits['timeout_seconds'] ?? config('research.limits.timeout_seconds'))),
        ]);

        if (! $root) {
            $job->update(['root_job_id' => $job->id]);   // a top-level job is its own root
        }

        return $job;
    }

    public function markCancelled(ResearchJob $job): void
    {
        $job->update(['status' => JobStatus::Cancelled, 'finished_at' => now()]);
    }

    public function continueRun(ResearchJob $job, array $config): void
    {
        $timeout = (int) ($config['limits']['timeout_seconds'] ?? config('research.limits.timeout_seconds'));

        $job->update([
            'status' => JobStatus::Running,
            'config' => $config,
            'finished_at' => null,
            'partial' => false,
            'parse_failures' => 0,
            'current_activity' => null,
            'deadline_at' => now()->addSeconds($timeout),
        ]);
    }

    public function extendBudget(ResearchJob $job, int $addIterations, int $addToolCalls, int $addSeconds): void
    {
        $config = $job->config;
        $config['limits']['max_iterations'] = $job->limit('max_iterations') + $addIterations;
        $config['limits']['max_tool_calls'] = $job->limit('max_tool_calls') + $addToolCalls;

        $job->update([
            'config' => $config,
            'deadline_at' => now()->addSeconds($addSeconds),
        ]);
    }

    public function complete(ResearchJob $job, string $report, ?float $confidence, bool $partial = false): void
    {
        $job->update([
            'status' => JobStatus::Completed,
            'final_report' => $report,
            'confidence' => $confidence,
            'partial' => $partial,
            'finished_at' => now(),
        ]);
    }

    public function fail(ResearchJob $job, string $error): void
    {
        $job->update([
            'status' => JobStatus::Failed,
            'last_error' => $error,
            'finished_at' => now(),
        ]);
    }

    public function setActivity(ResearchJob $job, ?string $activity): void
    {
        $job->forceFill([
            'current_activity' => $activity,
            'activity_updated_at' => now(),
        ])->save();
    }

    public function incrementIteration(ResearchJob $job): void
    {
        $job->increment('iteration');
    }

    public function incrementToolCalls(ResearchJob $job): void
    {
        $job->increment('tool_call_count');
    }

    public function incrementParseFailures(ResearchJob $job): int
    {
        $job->increment('parse_failures');

        return $job->refresh()->parse_failures;
    }

    public function resetParseFailures(ResearchJob $job): void
    {
        if ($job->parse_failures !== 0) {
            $job->update(['parse_failures' => 0]);
        }
    }

    public function recordStep(ResearchJob $job, int $iteration, StepType $type, ?string $thought = null, ?array $action = null): void
    {
        $job->steps()->create([
            'iteration' => $iteration,
            'type' => $type,
            'thought' => $thought,
            'action' => $action,
        ]);
    }

    public function recentToolExecutions(ResearchJob $job, int $limit = 20): array
    {
        return $job->toolExecutions()
            ->latest()
            ->limit($limit)
            ->get(['tool_name', 'fingerprint', 'status', 'error'])
            ->toArray();
    }

    public function markIterationCrashed(string $jobId, string $error): void
    {
        // A crashed iteration is not re-dispatched (tries=1), so leaving the job
        // "running" makes it look alive forever. Mark it failed so the state is
        // honest and visible in the UI; a supervisor can start a new run. All
        // context remains in the DB for inspection.
        DB::table('research_jobs')->where('id', $jobId)->update([
            'status' => JobStatus::Failed->value,
            'last_error' => 'Iteration crashed: '.$error,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
