<?php

namespace Tests\Feature;

use App\Application\Research\CancelResearch;
use App\Application\Research\ResearchOrchestrator;
use App\Application\Research\StartResearch;
use App\Domain\Research\Contracts\MemoryRepository;
use App\Domain\Research\Contracts\ResearchJobRepository;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\JobRole;
use App\Domain\Research\Enums\JobStatus;
use App\Domain\Research\Enums\TaskStatus;
use App\Jobs\AdvanceResearchJob;
use App\Models\ResearchJob;
use App\Models\ResearchTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Stopping a job must stop everything it spawned — a supervisor's workers,
 * reviewers, and sub-projects — and must never leave a still-running supervisor
 * parked on a sub-agent that was stopped by hand.
 */
class CancelResearchTest extends TestCase
{
    use RefreshDatabase;

    private function job(array $attrs = []): ResearchJob
    {
        return ResearchJob::create(array_merge([
            'goal' => 'g', 'status' => JobStatus::Running, 'config' => [],
        ], $attrs));
    }

    public function test_cancelling_a_supervisor_cancels_its_whole_sub_tree(): void
    {
        Queue::fake();

        $sup = $this->job(['role' => JobRole::Supervisor]);
        $worker = $this->job(['role' => JobRole::Worker, 'parent_job_id' => $sup->id]);
        $reviewer = $this->job(['role' => JobRole::Reviewer, 'parent_job_id' => $sup->id]);
        $subProject = $this->job(['role' => JobRole::Supervisor, 'parent_job_id' => $sup->id]);
        $grandchild = $this->job(['role' => JobRole::Worker, 'parent_job_id' => $subProject->id]);
        $unrelated = $this->job();

        $stopped = app(CancelResearch::class)->handle($sup);

        $this->assertSame(5, $stopped);
        foreach ([$sup, $worker, $reviewer, $subProject, $grandchild] as $j) {
            $this->assertSame(JobStatus::Cancelled, $j->refresh()->status, "job {$j->role->value} still running");
            $this->assertNotNull($j->finished_at);
        }
        $this->assertSame(JobStatus::Running, $unrelated->refresh()->status);
    }

    public function test_an_already_finished_child_is_left_alone(): void
    {
        Queue::fake();

        $sup = $this->job(['role' => JobRole::Supervisor]);
        $done = $this->job(['role' => JobRole::Worker, 'parent_job_id' => $sup->id, 'status' => JobStatus::Completed]);
        $running = $this->job(['role' => JobRole::Worker, 'parent_job_id' => $sup->id]);

        $this->assertSame(2, app(CancelResearch::class)->handle($sup));

        $this->assertSame(JobStatus::Completed, $done->refresh()->status);
        $this->assertSame(JobStatus::Cancelled, $running->refresh()->status);
    }

    public function test_in_flight_tasks_are_released_so_the_plan_is_honest(): void
    {
        Queue::fake();

        $sup = $this->job(['role' => JobRole::Supervisor]);
        $running = ResearchTask::create(['research_job_id' => $sup->id, 'seq' => 1, 'title' => 'A', 'brief' => 'b', 'status' => TaskStatus::InProgress]);
        $reviewing = ResearchTask::create(['research_job_id' => $sup->id, 'seq' => 2, 'title' => 'B', 'brief' => 'b', 'status' => TaskStatus::Reviewing]);
        $done = ResearchTask::create(['research_job_id' => $sup->id, 'seq' => 3, 'title' => 'C', 'brief' => 'b', 'status' => TaskStatus::Done]);

        app(CancelResearch::class)->handle($sup);

        $this->assertSame(TaskStatus::Pending, $running->refresh()->status);
        $this->assertSame(TaskStatus::Pending, $reviewing->refresh()->status);
        $this->assertSame(TaskStatus::Done, $done->refresh()->status);
    }

    public function test_stopping_one_worker_fails_its_task_and_wakes_the_supervisor(): void
    {
        Queue::fake();

        $sup = $this->job(['role' => JobRole::Supervisor]);
        $worker = $this->job(['role' => JobRole::Worker, 'parent_job_id' => $sup->id]);
        $task = ResearchTask::create(['research_job_id' => $sup->id, 'seq' => 1, 'title' => 'A', 'brief' => 'b',
            'status' => TaskStatus::InProgress, 'child_job_id' => $worker->id]);

        app(CancelResearch::class)->handle($worker);

        // The supervisor keeps running — it just must not wait on a stopped worker.
        $this->assertSame(JobStatus::Cancelled, $worker->refresh()->status);
        $this->assertSame(JobStatus::Running, $sup->refresh()->status);
        $this->assertSame(TaskStatus::Failed, $task->refresh()->status);
        Queue::assertPushed(AdvanceResearchJob::class, fn ($j) => $j->jobId === $sup->id);
    }

    public function test_stopping_a_reviewer_sends_its_task_back_for_review(): void
    {
        Queue::fake();

        $sup = $this->job(['role' => JobRole::Supervisor]);
        $task = ResearchTask::create(['research_job_id' => $sup->id, 'seq' => 1, 'title' => 'A', 'brief' => 'b',
            'status' => TaskStatus::Reviewing]);
        $reviewer = $this->job(['role' => JobRole::Reviewer, 'parent_job_id' => $sup->id,
            'config' => ['review_task_id' => $task->id]]);

        app(CancelResearch::class)->handle($reviewer);

        $this->assertSame(TaskStatus::AwaitingReview, $task->refresh()->status);
        Queue::assertPushed(AdvanceResearchJob::class, fn ($j) => $j->jobId === $sup->id);
    }

    public function test_a_worker_whose_parent_was_cancelled_stops_on_its_next_iteration(): void
    {
        Queue::fake();

        // The race the cascade can't cover: this worker was spawned by an
        // iteration already in flight when the supervisor was stopped.
        $sup = $this->job(['role' => JobRole::Supervisor, 'status' => JobStatus::Cancelled]);
        $worker = $this->job(['role' => JobRole::Worker, 'parent_job_id' => $sup->id]);

        app(ResearchOrchestrator::class)->advance($worker->id);

        $this->assertSame(JobStatus::Cancelled, $worker->refresh()->status);
        Queue::assertNotPushed(AdvanceResearchJob::class);
    }

    public function test_the_stop_endpoint_cascades(): void
    {
        Queue::fake();

        $sup = $this->job(['role' => JobRole::Supervisor]);
        $worker = $this->job(['role' => JobRole::Worker, 'parent_job_id' => $sup->id]);

        $this->post("/jobs/{$sup->id}/cancel")->assertRedirect();

        $this->assertSame(JobStatus::Cancelled, $sup->refresh()->status);
        $this->assertSame(JobStatus::Cancelled, $worker->refresh()->status);
    }

    /**
     * The plan must never parade a dead sub-agent as running. Rows can be left
     * in flight by a job stopped before the release existed, or by a cancel that
     * raced an iteration — either way the UI reports `stopped`, not IN PROGRESS.
     */
    public function test_the_plan_shows_a_stopped_task_instead_of_in_progress(): void
    {
        Queue::fake();

        $sup = $this->job(['role' => JobRole::Supervisor, 'status' => JobStatus::Cancelled]);
        $worker = $this->job(['role' => JobRole::Worker, 'parent_job_id' => $sup->id, 'status' => JobStatus::Cancelled]);
        ResearchTask::create(['research_job_id' => $sup->id, 'seq' => 1, 'title' => 'A', 'brief' => 'b',
            'status' => TaskStatus::InProgress, 'child_job_id' => $worker->id]);
        ResearchTask::create(['research_job_id' => $sup->id, 'seq' => 2, 'title' => 'B', 'brief' => 'b',
            'status' => TaskStatus::Done]);

        $extras = $this->getJson("/ui/api/jobs/{$sup->id}")->assertOk()->json('role_extras');

        $this->assertSame('stopped', $extras['tasks'][0]['status']);
        $this->assertSame('done', $extras['tasks'][1]['status']);
        $this->assertSame(0, $extras['task_counts']['in_progress']);
        $this->assertSame(1, $extras['task_counts']['stopped']);

        // Display only — the underlying row is untouched, so a rerun sees the truth.
        $this->assertSame(TaskStatus::InProgress, ResearchTask::where('seq', 1)->first()->status);
    }

    /** A task whose worker was cancelled under a STILL-RUNNING supervisor is stopped too. */
    public function test_a_task_held_by_a_cancelled_worker_is_shown_stopped(): void
    {
        $sup = $this->job(['role' => JobRole::Supervisor]);
        $worker = $this->job(['role' => JobRole::Worker, 'parent_job_id' => $sup->id, 'status' => JobStatus::Cancelled]);
        $task = ResearchTask::create(['research_job_id' => $sup->id, 'seq' => 1, 'title' => 'A', 'brief' => 'b',
            'status' => TaskStatus::InProgress, 'child_job_id' => $worker->id]);

        $this->assertSame('stopped', $task->displayStatus($sup, JobStatus::Cancelled->value));
        $this->assertSame('in_progress', $task->displayStatus($sup, JobStatus::Running->value));
    }

    /**
     * A cancel landing MID-PASS must not be undone by the rest of that pass. The
     * delegation loop is already past advance()'s runnable check, so it re-reads
     * the status between spawns: here the stop lands while task #1 is being
     * delegated, and task #2 must stay Pending instead of going in flight (and
     * then reading "IN PROGRESS" forever under a stopped job).
     */
    public function test_delegation_stops_as_soon_as_the_job_is_cancelled_mid_pass(): void
    {
        Queue::fake();

        $sup = $this->job(['role' => JobRole::Supervisor]);
        $one = ResearchTask::create(['research_job_id' => $sup->id, 'seq' => 1, 'title' => 'A', 'brief' => 'b', 'status' => TaskStatus::Pending]);
        $two = ResearchTask::create(['research_job_id' => $sup->id, 'seq' => 2, 'title' => 'B', 'brief' => 'b', 'status' => TaskStatus::Pending]);

        // Father hits STOP the moment the first worker is spawned.
        $spawner = new class(app(ResearchJobRepository::class), app(MemoryRepository::class), app(TraceRecorder::class)) extends StartResearch
        {
            public function spawnWorker(ResearchJob $parent, ResearchTask $task, string $goal, JobRole $role = JobRole::Worker, ?string $tierOverride = null): ResearchJob
            {
                $child = parent::spawnWorker($parent, $task, $goal, $role, $tierOverride);
                ResearchJob::whereKey($parent->id)->update(['status' => JobStatus::Cancelled->value]);

                return $child;
            }
        };
        app()->instance(StartResearch::class, $spawner);

        app(ResearchOrchestrator::class)->advance($sup->id);

        $this->assertSame(TaskStatus::InProgress, $one->refresh()->status);
        $this->assertSame(TaskStatus::Pending, $two->refresh()->status);
        $this->assertNull($two->child_job_id);
    }
}
