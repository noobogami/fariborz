<?php

namespace Tests\Feature;

use App\Application\Research\ResearchOrchestrator;
use App\Application\Research\StartResearch;
use App\Application\Research\Tools\ToolRegistry;
use App\Domain\Research\Contracts\LlmClient;
use App\Domain\Research\Enums\JobRole;
use App\Domain\Research\Enums\JobStatus;
use App\Domain\Research\Enums\TaskStatus;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Events\ResearchCompleted;
use App\Jobs\AdvanceResearchJob;
use App\Listeners\ResumeSupervisorOnChildDone;
use App\Models\ResearchJob;
use App\Models\ResearchTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeLlmClient;
use Tests\TestCase;

class SupervisorTest extends TestCase
{
    use RefreshDatabase;

    private function supervisor(string $goal = 'Build a big thing'): ResearchJob
    {
        return ResearchJob::create(['goal' => $goal, 'role' => JobRole::Supervisor, 'status' => JobStatus::Running, 'config' => []]);
    }

    private function ctx(ResearchJob $job): ResearchContext
    {
        return new ResearchContext($job, 0, $job->goal, [], [], [], role: $job->role);
    }

    public function test_role_gates_which_tools_each_role_sees(): void
    {
        $reg = app(ToolRegistry::class);

        $sup = collect($reg->definitions(null, JobRole::Supervisor))->pluck('name');
        $this->assertContains('plan_tasks', $sup);
        $this->assertContains('review_task', $sup);
        $this->assertNotContains('delegate_task', $sup); // delegation is deterministic (orchestrator), not a model choice
        $this->assertNotContains('browser_search', $sup); // supervisor doesn't do the work
        $this->assertNotContains('write_file', $sup);

        $worker = collect($reg->definitions(null, JobRole::Worker))->pluck('name');
        $this->assertContains('browser_search', $worker);
        $this->assertNotContains('delegate_task', $worker);  // workers can't delegate
        $this->assertNotContains('plan_tasks', $worker);
    }

    public function test_orchestrator_auto_delegates_ready_tasks_without_the_model(): void
    {
        Queue::fake();
        // No LLM decision needed — the orchestrator delegates deterministically.
        $this->app->instance(LlmClient::class, new FakeLlmClient([['action' => 'finish', 'report' => 'x', 'confidence' => 1]]));

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'A', 'brief' => 'b', 'status' => TaskStatus::Pending, 'depends_on' => []]);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 2, 'title' => 'B (blocked)', 'brief' => 'b', 'status' => TaskStatus::Pending, 'depends_on' => [1]]);

        app(ResearchOrchestrator::class)->advance($job->id);

        // #1 (ready) auto-started; #2 (dep unmet) stays pending; supervisor parked.
        $this->assertSame(TaskStatus::InProgress, ResearchTask::where('research_job_id', $job->id)->where('seq', 1)->first()->status);
        $this->assertSame(TaskStatus::Pending, ResearchTask::where('research_job_id', $job->id)->where('seq', 2)->first()->status);
        $this->assertSame('awaiting_worker', $job->refresh()->current_activity);
        $this->assertSame(1, ResearchJob::where('parent_job_id', $job->id)->count());
    }

    public function test_stall_breaker_stops_a_blocked_loop(): void
    {
        config()->set('research.limits.max_stalls', 3);
        // The model fixates on ONE calculator call: it succeeds once, then every
        // identical repeat is blocked as a duplicate — the classic thrash. The
        // stall breaker must stop the job instead of looping forever.
        $this->app->instance(LlmClient::class, new FakeLlmClient([
            ['action' => 'tool', 'tool' => 'calculator', 'arguments' => ['expression' => '1+1']],
        ]));

        $job = app(StartResearch::class)->handle('compute stuff');

        $job = ResearchJob::find($job->id);
        $this->assertTrue($job->status->isTerminal(), 'a blocked-action loop must terminate, not hang');
        $this->assertGreaterThanOrEqual(3, $job->iteration, 'blocked actions still advance the iteration');
    }

    public function test_plan_then_delegate_creates_a_worker_and_parks_the_supervisor(): void
    {
        Queue::fake();
        $job = $this->supervisor();

        app(ToolRegistry::class)->get('plan_tasks')->execute(new ToolArguments([
            'tasks' => [['title' => 'Write chapter 1', 'brief' => 'A full first chapter, ~1000 words.']],
        ]), $this->ctx($job));

        $this->assertDatabaseHas('research_tasks', ['research_job_id' => $job->id, 'seq' => 1, 'status' => 'pending']);

        $res = app(ToolRegistry::class)->get('delegate_task')->execute(new ToolArguments(['task' => 1]), $this->ctx($job));

        $this->assertTrue($res->success, 'the first task (no deps) delegates');
        $task = ResearchTask::where('research_job_id', $job->id)->where('seq', 1)->first();
        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertNotNull($task->child_job_id);

        $worker = ResearchJob::find($task->child_job_id);
        $this->assertSame(JobRole::Worker, $worker->role);
        $this->assertSame($job->id, $worker->parent_job_id);
        Queue::assertPushed(AdvanceResearchJob::class, fn ($j) => $j->jobId === $worker->id);
    }

    public function test_dependent_task_is_blocked_until_its_dependency_is_verified(): void
    {
        Queue::fake();
        $job = $this->supervisor();
        // Ch4 depends on Ch3; Ch3 is written but only AWAITING REVIEW (not verified).
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 3, 'title' => 'Ch3', 'brief' => 'b', 'status' => TaskStatus::AwaitingReview, 'result' => 'x']);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 4, 'title' => 'Ch4', 'brief' => 'b', 'status' => TaskStatus::Pending, 'depends_on' => [3]]);

        $res = app(ToolRegistry::class)->get('delegate_task')->execute(new ToolArguments(['task' => 4]), $this->ctx($job));

        $this->assertFalse($res->success, 'Ch4 must not start until Ch3 is verified (Done)');
        $this->assertStringContainsString('#3', $res->observation);
        $this->assertSame(0, ResearchJob::where('parent_job_id', $job->id)->count());
        $this->assertSame(TaskStatus::Pending, ResearchTask::where('research_job_id', $job->id)->where('seq', 4)->first()->status);
    }

    public function test_independent_tasks_run_in_parallel(): void
    {
        Queue::fake();
        $job = $this->supervisor();
        // Ch1 is running; the web server has NO dependencies → can start alongside it.
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Ch1', 'brief' => 'b', 'status' => TaskStatus::InProgress]);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 6, 'title' => 'Web server', 'brief' => 'b', 'status' => TaskStatus::Pending, 'depends_on' => []]);

        $res = app(ToolRegistry::class)->get('delegate_task')->execute(new ToolArguments(['task' => 6]), $this->ctx($job));

        $this->assertTrue($res->success, 'an independent task may run while another is in progress');
        $this->assertSame(TaskStatus::InProgress, ResearchTask::where('research_job_id', $job->id)->where('seq', 6)->first()->status);
        // Two workers now in flight — that is expected, not a bug.
        $this->assertSame(2, ResearchTask::where('research_job_id', $job->id)->where('status', 'in_progress')->count());
    }

    public function test_dependency_defaults_to_the_previous_task_when_unset(): void
    {
        Queue::fake();
        $job = $this->supervisor();
        // plan_tasks with no explicit deps → each chapter depends on the one before it.
        app(ToolRegistry::class)->get('plan_tasks')->execute(new ToolArguments([
            'tasks' => [['title' => 'Ch1', 'brief' => 'b'], ['title' => 'Ch2', 'brief' => 'b']],
        ]), $this->ctx($job));

        $ch2 = ResearchTask::where('research_job_id', $job->id)->where('seq', 2)->first();
        $this->assertSame([1], $ch2->depends_on, 'a task with no declared deps defaults to the previous task');

        // So Ch2 cannot start while Ch1 is still pending.
        $res = app(ToolRegistry::class)->get('delegate_task')->execute(new ToolArguments(['task' => 2]), $this->ctx($job));
        $this->assertFalse($res->success);
    }

    public function test_dependent_task_delegates_once_its_dependency_is_done(): void
    {
        Queue::fake();
        $job = $this->supervisor();
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Ch1', 'brief' => 'b', 'status' => TaskStatus::Done]);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 2, 'title' => 'Ch2', 'brief' => 'b', 'status' => TaskStatus::Pending, 'depends_on' => [1]]);

        $res = app(ToolRegistry::class)->get('delegate_task')->execute(new ToolArguments(['task' => 2]), $this->ctx($job));
        $this->assertTrue($res->success, 'with its dependency Done, the task may start');
        $this->assertSame(TaskStatus::InProgress, ResearchTask::where('research_job_id', $job->id)->where('seq', 2)->first()->status);
    }

    public function test_completed_worker_hands_result_back_and_wakes_the_supervisor(): void
    {
        Queue::fake();
        $job = $this->supervisor();
        $task = ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Ch1', 'brief' => 'write it', 'status' => TaskStatus::InProgress]);
        $worker = ResearchJob::create(['goal' => 'task', 'role' => JobRole::Worker, 'parent_job_id' => $job->id, 'status' => JobStatus::Completed, 'config' => [], 'final_report' => 'THE ACTUAL CHAPTER TEXT']);
        $task->update(['child_job_id' => $worker->id]);

        app(ResumeSupervisorOnChildDone::class)->handleCompleted(new ResearchCompleted($worker->id));

        $task->refresh();
        $this->assertSame(TaskStatus::AwaitingReview, $task->status);
        $this->assertStringContainsString('THE ACTUAL CHAPTER TEXT', $task->result);
        Queue::assertPushed(AdvanceResearchJob::class, fn ($j) => $j->jobId === $job->id);  // supervisor woken
    }

    public function test_review_accept_and_revise(): void
    {
        $job = $this->supervisor();
        $task = ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Ch1', 'brief' => 'write it', 'status' => TaskStatus::AwaitingReview, 'result' => 'x']);

        app(ToolRegistry::class)->get('review_task')->execute(new ToolArguments(['task' => 1, 'verdict' => 'accept']), $this->ctx($job));
        $this->assertSame(TaskStatus::Done, $task->refresh()->status);

        // Revise sends it back to pending with notes appended to the brief.
        $task->update(['status' => TaskStatus::AwaitingReview]);
        app(ToolRegistry::class)->get('review_task')->execute(new ToolArguments(['task' => 1, 'verdict' => 'revise', 'notes' => 'too short']), $this->ctx($job));
        $task->refresh();
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertStringContainsString('too short', $task->brief);
    }

    public function test_supervisor_advance_plans_then_delegates_without_rescheduling_itself(): void
    {
        Queue::fake();
        config()->set('research.supervisor.comprehension', false); // isolate plan→delegate mechanics
        $this->app->instance(LlmClient::class, new FakeLlmClient([
            ['thought' => 'plan', 'action' => 'tool', 'tool' => 'plan_tasks', 'arguments' => ['tasks' => [['title' => 'T1', 'brief' => 'do t1']]]],
            ['thought' => 'delegate', 'action' => 'tool', 'tool' => 'delegate_task', 'arguments' => ['task' => 1]],
        ]));

        $job = $this->supervisor();
        $orch = app(ResearchOrchestrator::class);

        $orch->advance($job->id);   // plan_tasks → reschedules supervisor
        $this->assertSame(1, ResearchTask::where('research_job_id', $job->id)->count());

        $orch->advance($job->id);   // delegate_task → spawns worker, PARKS (no self-reschedule)
        $this->assertSame('awaiting_worker', $job->refresh()->current_activity);

        // Exactly one worker was spawned; the only supervisor re-dispatch was the
        // post-plan reschedule (the delegate parked instead of rescheduling).
        $worker = ResearchJob::where('parent_job_id', $job->id)->first();
        $this->assertNotNull($worker);
        Queue::assertPushed(AdvanceResearchJob::class, fn ($j) => $j->jobId === $worker->id);
        Queue::assertPushed(AdvanceResearchJob::class, 2); // 1 supervisor reschedule + 1 worker
    }

    public function test_supervisor_auto_extends_budget_while_tasks_remain(): void
    {
        Queue::fake();
        // Budget already exhausted, but a task is still open.
        $job = ResearchJob::create([
            'goal' => 'big', 'role' => JobRole::Supervisor, 'status' => JobStatus::Running,
            'config' => ['limits' => ['max_iterations' => 5, 'max_tool_calls' => 5, 'timeout_seconds' => 3600]],
        ]);
        $job->forceFill(['iteration' => 5])->save();
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'T', 'brief' => 'b', 'status' => TaskStatus::Pending]);

        // No LLM decision needed: preflight guardrail stops on max_iterations first.
        $this->app->instance(LlmClient::class, new FakeLlmClient([['action' => 'finish', 'report' => 'x', 'confidence' => 1]]));
        app(ResearchOrchestrator::class)->advance($job->id);

        $job->refresh();
        $this->assertSame(JobStatus::Running, $job->status, 'must NOT finish while tasks remain');
        $this->assertGreaterThan(5, $job->limit('max_iterations'), 'budget should be topped up');
        Queue::assertPushed(AdvanceResearchJob::class, fn ($j) => $j->jobId === $job->id);
    }

    public function test_started_supervised_project_has_supervisor_role(): void
    {
        Queue::fake();
        $job = app(StartResearch::class)->handle('A large multi-part goal', [], JobRole::Supervisor);
        $this->assertSame(JobRole::Supervisor, $job->refresh()->role);
    }

    public function test_all_sub_agents_share_the_root_project_workspace(): void
    {
        Queue::fake();
        $sup = app(StartResearch::class)->handle('big project', [], JobRole::Supervisor);
        $task = ResearchTask::create(['research_job_id' => $sup->id, 'seq' => 1, 'title' => 'T', 'brief' => 'b', 'status' => TaskStatus::Pending, 'depends_on' => []]);

        $worker = app(StartResearch::class)->spawnWorker($sup, $task, 'do T');

        // The worker builds in the SUPERVISOR's workspace, not its own — keyed by
        // the root's human-readable slug so the sandbox folder is findable.
        $this->assertSame($sup->id, $worker->root_job_id);
        $this->assertSame($sup->slug, $worker->workspace_slug);
        $ctx = new ResearchContext($worker->refresh(), 0, 'do T', [], [], [], role: JobRole::Worker);
        $this->assertSame($sup->slug, $ctx->workspaceId());
    }

    public function test_delegating_project_mode_spawns_a_sub_supervisor(): void
    {
        Queue::fake();
        $sup = app(StartResearch::class)->handle('huge goal', [], JobRole::Supervisor);
        ResearchTask::create(['research_job_id' => $sup->id, 'seq' => 1, 'title' => 'Write the novel', 'brief' => 'b', 'status' => TaskStatus::Pending, 'depends_on' => []]);

        app(ToolRegistry::class)->get('delegate_task')->execute(
            new ToolArguments(['task' => 1, 'mode' => 'project']), $this->ctx($sup->refresh())
        );

        $child = ResearchJob::where('parent_job_id', $sup->id)->first();
        $this->assertSame(JobRole::Supervisor, $child->role, 'a too-big task becomes a sub-project');
        $this->assertSame($sup->id, $child->root_job_id, 'the sub-project shares the root workspace');
    }

    public function test_sub_project_delegation_downgrades_to_worker_past_max_depth(): void
    {
        Queue::fake();
        config()->set('research.supervisor.max_depth', 1);
        $root = app(StartResearch::class)->handle('root', [], JobRole::Supervisor);
        // A sub-supervisor at depth 1 (== max) may not spawn another sub-supervisor.
        $sub = ResearchJob::create(['goal' => 'sub', 'role' => JobRole::Supervisor, 'parent_job_id' => $root->id, 'root_job_id' => $root->id, 'status' => JobStatus::Running, 'config' => []]);
        ResearchTask::create(['research_job_id' => $sub->id, 'seq' => 1, 'title' => 'T', 'brief' => 'b', 'status' => TaskStatus::Pending, 'depends_on' => []]);

        app(ToolRegistry::class)->get('delegate_task')->execute(
            new ToolArguments(['task' => 1, 'mode' => 'project']), $this->ctx($sub)
        );

        $child = ResearchJob::where('parent_job_id', $sub->id)->first();
        $this->assertSame(JobRole::Worker, $child->role, 'past max depth, project downgrades to worker');
    }

    public function test_finished_sub_supervisor_resumes_its_parent(): void
    {
        Queue::fake();
        $root = app(StartResearch::class)->handle('root', [], JobRole::Supervisor);
        $task = ResearchTask::create(['research_job_id' => $root->id, 'seq' => 1, 'title' => 'Big part', 'brief' => 'b', 'status' => TaskStatus::InProgress]);
        $sub = ResearchJob::create(['goal' => 'sub', 'role' => JobRole::Supervisor, 'parent_job_id' => $root->id, 'root_job_id' => $root->id, 'status' => JobStatus::Completed, 'config' => [], 'final_report' => 'SUB RESULT']);
        $task->update(['child_job_id' => $sub->id]);

        app(ResumeSupervisorOnChildDone::class)->handleCompleted(new ResearchCompleted($sub->id));

        $this->assertSame(TaskStatus::AwaitingReview, $task->refresh()->status);
        $this->assertStringContainsString('SUB RESULT', $task->result);
        Queue::assertPushed(AdvanceResearchJob::class, fn ($j) => $j->jobId === $root->id);
    }

    public function test_supervisor_understands_the_goal_before_planning(): void
    {
        Queue::fake();
        config()->set('research.supervisor.comprehension', true);
        // First LLM turn is the comprehension call (intake analyst), NOT a decision.
        $this->app->instance(LlmClient::class, new FakeLlmClient([
            json_encode([
                'restatement' => 'Deploy a switchable-scenario UI first, then fill it in.',
                'constraints' => ['Spawn at least 100 sub-agents/tasks (counts AGENTS, not scenarios)'],
                'ordering' => ['Deploy the UI FIRST, before generating scenarios'],
                'deliverable' => 'A served web app with switchable scenarios',
                'acceptance' => ['UI reachable at a published port'],
            ]),
        ]));

        $job = app(StartResearch::class)->handle('deploy ui first; wont accept less than 100 agents', [], JobRole::Supervisor);
        app(ResearchOrchestrator::class)->advance($job->id);

        $job->refresh();
        $this->assertNotEmpty($job->requirements, 'the goal spec is extracted and stored before any planning');
        $this->assertContains('Deploy the UI FIRST, before generating scenarios', $job->requirements['ordering']);
        $this->assertStringContainsString('AGENTS', $job->requirements['constraints'][0]);
        // No task was planned this turn — comprehension ran, then rescheduled.
        $this->assertSame(0, ResearchTask::where('research_job_id', $job->id)->count());
        Queue::assertPushed(AdvanceResearchJob::class, fn ($j) => $j->jobId === $job->id);
    }

    public function test_comprehension_stores_a_fallback_when_the_model_reply_is_unparseable(): void
    {
        Queue::fake();
        config()->set('research.supervisor.comprehension', true);
        $this->app->instance(LlmClient::class, new FakeLlmClient(['not json at all']));

        $job = app(StartResearch::class)->handle('some goal', [], JobRole::Supervisor);
        app(ResearchOrchestrator::class)->advance($job->id);

        $job->refresh();
        // A non-null, well-shaped spec so comprehension never loops.
        $this->assertNotEmpty($job->requirements);
        $this->assertSame('some goal', $job->requirements['restatement']);
        $this->assertSame([], $job->requirements['ordering']);
    }

    public function test_worker_brief_carries_dependency_outputs_and_forbids_redoing_work(): void
    {
        Queue::fake();
        config()->set('research.supervisor.comprehension', false);
        $job = $this->supervisor('Research remote work, then combine into notes.md');
        $job->update(['requirements' => ['constraints' => ['Keep it small'], 'ordering' => [], 'acceptance' => []]]);

        // #1 is DONE and produced a file + a result; #2 combines it and is ready.
        ResearchTask::create([
            'research_job_id' => $job->id, 'seq' => 1, 'title' => 'Research Pros', 'brief' => 'find pros',
            'status' => TaskStatus::Done, 'outputs' => ['pros.md'], 'result' => 'PROS: flexibility, less commuting, focus',
        ]);
        ResearchTask::create([
            'research_job_id' => $job->id, 'seq' => 2, 'title' => 'Combine', 'brief' => 'combine into notes.md',
            'status' => TaskStatus::Pending, 'depends_on' => [1], 'outputs' => ['notes.md'],
        ]);

        app(ResearchOrchestrator::class)->advance($job->id);

        $worker = ResearchJob::where('parent_job_id', $job->id)->first();
        $this->assertNotNull($worker, 'the ready combine task was delegated');
        $this->assertStringContainsString('INPUTS ALREADY PRODUCED', $worker->goal);
        $this->assertStringContainsString('PROS: flexibility', $worker->goal, 'the dependency result is handed over');
        $this->assertStringContainsString('pros.md', $worker->goal, 'the dependency output file is named');
        $this->assertStringContainsString('notes.md', $worker->goal, 'the write target is named');
        $this->assertStringContainsString('Do NOT search', $worker->goal, 'it is told not to redo research');
        $this->assertStringContainsString('Keep it small', $worker->goal, 'project constraints reach the worker');
    }

    public function test_parked_supervisor_shows_waiting_not_stalled(): void
    {
        Cache::put('research:worker:last_seen', time(), 120);
        $job = $this->supervisor();
        // Parked for a long time waiting on a worker — must NOT read as "stuck".
        $job->forceFill(['current_activity' => 'awaiting_worker', 'activity_updated_at' => now()->subSeconds(600)])->save();
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 3, 'title' => 'Write chapter 3', 'brief' => 'b', 'status' => TaskStatus::InProgress]);

        $this->getJson("/ui/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('activity.phase', 'awaiting_worker')
            ->assertJsonPath('activity.waiting_for', 'worker sub-agent to finish task #3');
    }
}
