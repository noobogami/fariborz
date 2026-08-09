<?php

namespace Tests\Feature;

use App\Application\Research\CancelResearch;
use App\Application\Research\ResearchOrchestrator;
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
}
