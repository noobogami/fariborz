<?php

namespace Tests\Feature;

use App\Application\Research\Llm\ModelAvailability;
use App\Application\Research\Llm\ModelCatalog;
use App\Application\Research\ResearchOrchestrator;
use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\StartResearch;
use App\Application\Research\Tools\ArtifactChecks;
use App\Application\Research\Tools\ToolRegistry;
use App\Domain\Research\Contracts\LlmClient;
use App\Domain\Research\Enums\JobRole;
use App\Domain\Research\Enums\JobStatus;
use App\Domain\Research\Enums\TaskStatus;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Events\ResearchCompleted;
use App\Events\ResearchFailed;
use App\Infrastructure\Research\Tools\ReviewTaskTool;
use App\Jobs\AdvanceResearchJob;
use App\Listeners\ResumeSupervisorOnChildDone;
use App\Models\ResearchJob;
use App\Models\ResearchTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeLlmClient;
use Tests\TestCase;

class SupervisorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tier→model resolution now goes through the gateway's catalogue, so make
        // the fake gateway serve exactly the names these tests configure: they are
        // about availability routing, not about stale model names. Registered first
        // and scoped to the catalogue URL, so a test's own stubs still win for
        // /read, /health, etc.
        config(['research.llm.catalog_ttl' => 0]);
        Http::fake(['*/v1/models' => fn () => Http::response([
            'data' => array_map(fn ($n) => ['id' => $n], app(ModelCatalog::class)->configured()),
        ])]);
    }

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

    public function test_reviewer_tool_gate_is_read_only_plus_submit_review(): void
    {
        $reg = app(ToolRegistry::class);
        $names = collect($reg->definitions(null, JobRole::Reviewer))->pluck('name');

        $this->assertContains('submit_review', $names);
        $this->assertContains('read_file', $names);
        $this->assertContains('list_files', $names);
        $this->assertContains('run_command', $names);
        $this->assertContains('container_logs', $names);
        $this->assertContains('list_processes', $names);

        // Never "do the work" or other control tools.
        $this->assertNotContains('write_file', $names);
        $this->assertNotContains('plan_tasks', $names);
        $this->assertNotContains('delegate_task', $names);
        $this->assertNotContains('review_task', $names);
        $this->assertNotContains('start_server', $names);
        $this->assertNotContains('browser_search', $names);
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

    public function test_a_task_that_exceeds_the_attempt_cap_is_force_accepted_not_redelegated(): void
    {
        Queue::fake();
        config(['research.supervisor.max_task_attempts' => 3]);
        // Finish is available if the supervisor proceeds after the loop is broken.
        $this->app->instance(LlmClient::class, new FakeLlmClient([['action' => 'finish', 'report' => 'x', 'confidence' => 0.4]]));

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        // A ready task already delegated `cap` times — the endless revise loop.
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Loopy', 'brief' => 'b',
            'status' => TaskStatus::Pending, 'depends_on' => [], 'attempts' => 3]);

        app(ResearchOrchestrator::class)->advance($job->id);

        // Force-accepted as best-effort (Done) rather than re-delegated — no new worker.
        $this->assertSame(TaskStatus::Done, ResearchTask::where('research_job_id', $job->id)->where('seq', 1)->first()->status);
        $this->assertSame(0, ResearchJob::where('parent_job_id', $job->id)->count());
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

    public function test_failed_worker_is_requeued_deterministically_not_sent_to_review(): void
    {
        // A worker crash / rate-limit is NOT a review case. It must go straight back
        // to Pending (so the orchestrator re-delegates it and the supervisor parks
        // with no LLM turn) — never to AwaitingReview, which would burn a review turn
        // and grow the transcript toward the context-window crash.
        Queue::fake();
        $job = $this->supervisor();
        $task = ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Assemble', 'brief' => 'do it', 'status' => TaskStatus::InProgress]);
        $worker = ResearchJob::create(['goal' => 'task', 'role' => JobRole::Worker, 'parent_job_id' => $job->id, 'status' => JobStatus::Failed, 'config' => []]);
        $task->update(['child_job_id' => $worker->id]);

        app(ResumeSupervisorOnChildDone::class)->handleFailed(new ResearchFailed($worker->id, 'LLM gateway request failed: 429 rate limit'));

        $task->refresh();
        $this->assertSame(TaskStatus::Pending, $task->status, 'a failed worker requeues the task, it is not reviewed');
        $this->assertStringStartsWith(ResearchTask::WORKER_ERROR_PREFIX, $task->result);
        Queue::assertPushed(AdvanceResearchJob::class, fn ($j) => $j->jobId === $job->id);  // supervisor woken to re-delegate
    }

    public function test_a_task_that_only_ever_failed_is_marked_failed_not_force_accepted(): void
    {
        // Attempt cap reached AND the last outcome was a worker error → the task has
        // no artifact worth keeping, so it must be marked Failed (honest) rather than
        // paraded as Done. Contrast test_a_task_that_exceeds_the_attempt_cap... which
        // force-accepts a task that DID produce output but kept getting revised.
        Queue::fake();
        config(['research.supervisor.max_task_attempts' => 3]);
        $this->app->instance(LlmClient::class, new FakeLlmClient([['action' => 'finish', 'report' => 'x', 'confidence' => 0.3]]));

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Rate-limited', 'brief' => 'b',
            'status' => TaskStatus::Pending, 'depends_on' => [], 'attempts' => 3,
            'result' => ResearchTask::WORKER_ERROR_PREFIX.'429 rate limit']);

        app(ResearchOrchestrator::class)->advance($job->id);

        $this->assertSame(TaskStatus::Failed, ResearchTask::where('research_job_id', $job->id)->where('seq', 1)->first()->status);
        $this->assertSame(0, ResearchJob::where('parent_job_id', $job->id)->count(), 'no new worker spawned');
    }

    public function test_supervisor_finishes_deterministically_when_all_tasks_are_settled(): void
    {
        // Every task terminal (Done/Failed) → Fariborz assembles the report in code
        // and completes, WITHOUT an LLM finish turn. The scripted LLM would throw a
        // parse error if consulted (it's not a valid decision), proving no LLM call.
        Queue::fake();
        $this->app->instance(LlmClient::class, new FakeLlmClient(['not-json — must never be read']));

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        $job->update(['requirements' => ['restatement' => 'x']]); // skip comprehension turn
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Chapter', 'brief' => 'b',
            'status' => TaskStatus::Done, 'depends_on' => [], 'result' => 'the chapter text', 'outputs' => ['chapter.md']]);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 2, 'title' => 'Broken', 'brief' => 'b',
            'status' => TaskStatus::Failed, 'depends_on' => [], 'result' => ResearchTask::WORKER_ERROR_PREFIX.'429']);

        app(ResearchOrchestrator::class)->advance($job->id);

        $job->refresh();
        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertStringContainsString('Chapter', (string) $job->final_report);
        $this->assertStringContainsString('did not complete', (string) $job->final_report); // the Failed task is flagged honestly
        // A markdown deliverable isn't a page — no preview link to promise.
        $this->assertStringNotContainsString('Preview:', (string) $job->final_report);
    }

    public function test_the_report_points_at_the_static_preview_when_the_deliverable_is_a_page(): void
    {
        // The sandbox already serves every workspace statically, so an HTML
        // artifact is viewable with no server written for it. The link is added
        // in code — no worker prompt carries a rule about it.
        Queue::fake();
        $this->app->instance(LlmClient::class, new FakeLlmClient(['not-json — must never be read']));

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        $job->update(['requirements' => ['restatement' => 'x']]);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Page', 'brief' => 'b',
            'status' => TaskStatus::Done, 'depends_on' => [], 'result' => 'wrote it', 'outputs' => ['./index.html']]);

        app(ResearchOrchestrator::class)->advance($job->id);

        $job->refresh();
        // "./index.html" is what /preview/<workspace>/ resolves to on its own.
        $this->assertStringContainsString("Preview: /sandbox/preview/{$job->workspace_slug}/\n", (string) $job->final_report);
    }

    public function test_the_report_names_the_page_when_it_is_not_a_root_index(): void
    {
        Queue::fake();
        $this->app->instance(LlmClient::class, new FakeLlmClient(['not-json — must never be read']));

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        $job->update(['requirements' => ['restatement' => 'x']]);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Page', 'brief' => 'b',
            'status' => TaskStatus::Done, 'depends_on' => [], 'result' => 'wrote it', 'outputs' => ['site/report.html']]);

        app(ResearchOrchestrator::class)->advance($job->id);

        $job->refresh();
        $this->assertStringContainsString("Preview: /sandbox/preview/{$job->workspace_slug}/site/report.html",
            (string) $job->final_report);
    }

    public function test_the_report_does_not_link_a_page_from_a_task_that_failed(): void
    {
        // A declared output on a Failed task may never have been written — the
        // report must not point at a 404 and call it a deliverable.
        Queue::fake();
        $this->app->instance(LlmClient::class, new FakeLlmClient(['not-json — must never be read']));

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        $job->update(['requirements' => ['restatement' => 'x']]);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Page', 'brief' => 'b',
            'status' => TaskStatus::Failed, 'depends_on' => [], 'result' => ResearchTask::WORKER_ERROR_PREFIX.'429',
            'outputs' => ['index.html']]);

        app(ResearchOrchestrator::class)->advance($job->id);

        $job->refresh();
        $this->assertStringNotContainsString('Preview:', (string) $job->final_report);
    }

    public function test_completed_worker_no_longer_eagerly_injects_the_artifact_then_a_reviewer_is_spawned(): void
    {
        // The OLD eager artifact-load on every worker completion is gone — a
        // dedicated Reviewer agent now reads the real files itself. This test
        // proves BOTH halves: no injection right after the worker completes, and
        // the very next orchestrator turn deterministically spawns a Reviewer
        // (task → Reviewing) once the pre-gate passes.
        Queue::fake();
        Http::fake(['*/read*' => Http::response(['content' => "<h1>ONCE UPON A TIME</h1>\nreal page markup"])]);

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        $job->update(['requirements' => ['restatement' => 'x']]); // skip comprehension
        $task = ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Page', 'brief' => 'build it',
            'status' => TaskStatus::InProgress, 'outputs' => ['index.html']]);
        $worker = ResearchJob::create(['goal' => 'task', 'role' => JobRole::Worker, 'parent_job_id' => $job->id,
            'status' => JobStatus::Completed, 'config' => [], 'final_report' => 'I built the page']);
        $task->update(['child_job_id' => $worker->id]);

        app(ResumeSupervisorOnChildDone::class)->handleCompleted(new ResearchCompleted($worker->id));

        $task->refresh();
        $this->assertSame(TaskStatus::AwaitingReview, $task->status);
        $observation = $job->messages()->get()->pluck('content')->implode("\n");
        $this->assertStringNotContainsString('ONCE UPON A TIME', $observation, 'no eager artifact injection any more');

        app(ResearchOrchestrator::class)->advance($job->id);

        $task->refresh();
        $this->assertSame(TaskStatus::Reviewing, $task->status, 'the pre-gate passed and a reviewer was spawned');
        $reviewer = ResearchJob::where('parent_job_id', $job->id)->where('role', JobRole::Reviewer)->first();
        $this->assertNotNull($reviewer);
        $this->assertSame($task->id, $reviewer->config['review_task_id']);
    }

    public function test_pregate_rejects_a_missing_output_and_revises_without_spawning_a_reviewer(): void
    {
        // The deterministic pre-gate may only REJECT — a provably missing/empty
        // declared output sends the task straight back to revise, with no
        // reviewer agent spawned at all (that would be pure waste).
        Queue::fake();
        Http::fake(['*/read*' => Http::response(['error' => 'ENOENT'], 404)]);
        $this->app->instance(LlmClient::class, new FakeLlmClient([['action' => 'finish', 'report' => 'x', 'confidence' => 0.3]]));

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        $job->update(['requirements' => ['restatement' => 'x']]);
        $task = ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'UI', 'brief' => 'build ui',
            'status' => TaskStatus::AwaitingReview, 'result' => 'done!', 'outputs' => ['ui/index.html']]);

        app(ResearchOrchestrator::class)->advance($job->id);

        $task->refresh();
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertStringContainsString('REVISION NEEDED', $task->brief);
        $this->assertSame(0, ResearchJob::where('parent_job_id', $job->id)->where('role', JobRole::Reviewer)->count());
    }

    public function test_reviewer_spawn_routes_around_a_model_in_cooldown(): void
    {
        // The reviewer's INTENDED tier (here: default_tier, since reviewer_tier
        // and the task's own tier are both blank) resolves to a model already in
        // cooldown — the spawn must land on an available alternative tier instead
        // of the dead one (which would just fail again and dump onto the
        // supervisor's review_task fallback).
        Queue::fake();
        config([
            'research.llm.tiers.standard.model' => 'dead-model',
            'research.llm.tiers.light.model' => 'dead-model', // also the dead model → must be skipped
            'research.llm.tiers.hard.model' => 'cloud-model',
            'research.llm.default_tier' => 'standard',
            'research.supervisor.reviewer_tier' => '',
        ]);
        // Single Http::fake call covers both the sandbox artifact read (pre-gate)
        // AND the single-model health probe for the replacement — Http::fake
        // MERGES stubs across calls, but a second call is unnecessary here.
        Http::fake([
            '*/read*' => Http::response(['content' => str_repeat('<div>real content</div>', 5)]),
            '*/health*' => Http::response(['healthy_count' => 1, 'unhealthy_count' => 0]),
        ]);

        app(ModelAvailability::class)->markUnavailable('dead-model', '429 too many requests');

        $job = $this->supervisor();
        $job->update(['requirements' => ['restatement' => 'x']]);
        $task = ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'UI', 'brief' => 'build ui',
            'status' => TaskStatus::AwaitingReview, 'result' => 'done!', 'outputs' => ['ui/index.html']]);

        app(ResearchOrchestrator::class)->advance($job->id);

        $task->refresh();
        $this->assertSame(TaskStatus::Reviewing, $task->status, 'the pre-gate passed and a reviewer was spawned');
        $reviewer = ResearchJob::where('parent_job_id', $job->id)->where('role', JobRole::Reviewer)->first();
        $this->assertNotNull($reviewer);
        $this->assertSame('hard', $reviewer->config['tier'], 'routed to the available tier, not the throttled default');
    }

    public function test_reviewer_disabled_leaves_task_for_the_supervisor_fallback(): void
    {
        Queue::fake();
        config(['research.supervisor.reviewer_enabled' => false]);
        Http::fake(['*/read*' => Http::response(['content' => str_repeat('<div>real content</div>', 5)])]);
        $this->app->instance(LlmClient::class, new FakeLlmClient([['action' => 'finish', 'report' => 'x', 'confidence' => 0.5]]));

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        $job->update(['requirements' => ['restatement' => 'x']]);
        $task = ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'UI', 'brief' => 'build ui',
            'status' => TaskStatus::AwaitingReview, 'result' => 'done!', 'outputs' => ['ui/index.html']]);

        app(ResearchOrchestrator::class)->advance($job->id);

        $task->refresh();
        $this->assertSame(TaskStatus::AwaitingReview, $task->status, 'left for the supervisor LLM fallback, unchanged path');
        $this->assertSame(0, ResearchJob::where('parent_job_id', $job->id)->count());
    }

    public function test_reviewing_task_counts_as_in_flight_and_blocks_deterministic_finish(): void
    {
        Queue::fake();
        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        $job->update(['requirements' => ['restatement' => 'x']]);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'A', 'brief' => 'b',
            'status' => TaskStatus::Done, 'depends_on' => [], 'result' => 'x']);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 2, 'title' => 'B', 'brief' => 'b',
            'status' => TaskStatus::Reviewing, 'depends_on' => []]);

        app(ResearchOrchestrator::class)->advance($job->id);

        $job->refresh();
        $this->assertNotSame(JobStatus::Completed, $job->status, 'must not finish while a reviewer is running');
        $this->assertSame('awaiting_worker', $job->current_activity);
        $this->assertTrue($job->supervisorShouldWait(), 'a Reviewing task is in-flight, just like InProgress');
    }

    public function test_reviewer_accept_marks_the_task_done(): void
    {
        Queue::fake();
        $job = $this->supervisor();
        $task = ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'UI', 'brief' => 'build ui',
            'status' => TaskStatus::Reviewing, 'result' => 'done!']);
        $reviewer = ResearchJob::create(['goal' => 'review', 'role' => JobRole::Reviewer, 'parent_job_id' => $job->id,
            'status' => JobStatus::Completed, 'config' => ['review_task_id' => $task->id],
            'review_verdict' => ['verdict' => 'accept', 'notes' => 'looks good', 'confidence' => 0.9]]);

        app(ResumeSupervisorOnChildDone::class)->handleCompleted(new ResearchCompleted($reviewer->id));

        $task->refresh();
        $this->assertSame(TaskStatus::Done, $task->status);
        Queue::assertPushed(AdvanceResearchJob::class, fn ($j) => $j->jobId === $job->id);
    }

    public function test_reviewer_revise_sends_the_task_back_with_notes(): void
    {
        Queue::fake();
        $job = $this->supervisor();
        $task = ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'UI', 'brief' => 'build ui',
            'status' => TaskStatus::Reviewing, 'result' => 'done!', 'attempts' => 1]);
        $reviewer = ResearchJob::create(['goal' => 'review', 'role' => JobRole::Reviewer, 'parent_job_id' => $job->id,
            'status' => JobStatus::Completed, 'config' => ['review_task_id' => $task->id],
            'review_verdict' => ['verdict' => 'revise', 'notes' => 'missing footer', 'confidence' => 0.4]]);

        app(ResumeSupervisorOnChildDone::class)->handleCompleted(new ResearchCompleted($reviewer->id));

        $task->refresh();
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertStringContainsString('missing footer', $task->brief);
        // Revising does not itself bump attempts — only (re)delegation does, so a
        // task's total tries stay bounded by the SAME max_task_attempts cap
        // whether it was revised by a worker failure or a reviewer.
        $this->assertSame(1, $task->attempts);
    }

    public function test_reviewer_with_no_verdict_falls_back_to_supervisor_review_with_artifact(): void
    {
        // The reviewer finished (e.g. ran out of turns) without ever calling
        // submit_review — fall back to the supervisor's own review_task, WITH
        // the real artifact injected (the eager-load helper moved here).
        Queue::fake();
        $sandbox = new class extends SandboxClient
        {
            public function __construct() {}

            public function read(string $job, string $path): array
            {
                return ['content' => "REAL PAGE CONTENT for {$path}"];
            }
        };
        $this->app->instance(SandboxClient::class, $sandbox);

        $job = $this->supervisor();
        $task = ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'UI', 'brief' => 'build ui',
            'status' => TaskStatus::Reviewing, 'result' => 'done!', 'outputs' => ['index.html']]);
        $reviewer = ResearchJob::create(['goal' => 'review', 'role' => JobRole::Reviewer, 'parent_job_id' => $job->id,
            'status' => JobStatus::Completed, 'config' => ['review_task_id' => $task->id], 'final_report' => 'gave up']);

        app(ResumeSupervisorOnChildDone::class)->handleCompleted(new ResearchCompleted($reviewer->id));

        $task->refresh();
        $this->assertSame(TaskStatus::AwaitingReview, $task->status);
        $observation = $job->messages()->get()->pluck('content')->implode("\n");
        $this->assertStringContainsString('REAL PAGE CONTENT', $observation);
        $this->assertStringContainsString('without a verdict', $observation);
        Queue::assertPushed(AdvanceResearchJob::class, fn ($j) => $j->jobId === $job->id);
    }

    public function test_reviewer_crash_falls_back_to_supervisor_review_not_a_worker_redo(): void
    {
        // A crashed/unavailable reviewer is NOT evidence against the worker's
        // task — it must fall back to AwaitingReview, never back to Pending
        // (which would needlessly re-run the worker).
        Queue::fake();
        $job = $this->supervisor();
        $task = ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'UI', 'brief' => 'build ui',
            'status' => TaskStatus::Reviewing, 'result' => 'done!']);
        $reviewer = ResearchJob::create(['goal' => 'review', 'role' => JobRole::Reviewer, 'parent_job_id' => $job->id,
            'status' => JobStatus::Failed, 'config' => ['review_task_id' => $task->id]]);

        app(ResumeSupervisorOnChildDone::class)->handleFailed(new ResearchFailed($reviewer->id, 'gateway 503'));

        $task->refresh();
        $this->assertSame(TaskStatus::AwaitingReview, $task->status, 'falls back, does not redo the worker');
        Queue::assertPushed(AdvanceResearchJob::class, fn ($j) => $j->jobId === $job->id);
    }

    public function test_submit_review_tool_records_the_verdict_on_its_own_job(): void
    {
        $job = ResearchJob::create(['goal' => 'review task', 'role' => JobRole::Reviewer, 'status' => JobStatus::Running, 'config' => []]);

        $res = app(ToolRegistry::class)->get('submit_review')->execute(
            new ToolArguments(['verdict' => 'accept', 'notes' => 'checked the file, it is real', 'confidence' => 0.8]),
            $this->ctx($job)
        );

        $this->assertTrue($res->success);
        $job->refresh();
        $this->assertSame('accept', $job->review_verdict['verdict']);
        $this->assertSame('checked the file, it is real', $job->review_verdict['notes']);
        $this->assertEqualsWithDelta(0.8, $job->review_verdict['confidence'], 0.001);
    }

    public function test_orchestrator_ends_the_reviewer_run_when_submit_review_is_called(): void
    {
        // submit_review is a normal TOOL CALL, not a "finish" action — but calling
        // it must still end the reviewer's job deterministically (the orchestrator
        // decides, not the model).
        Queue::fake();
        $this->app->instance(LlmClient::class, new FakeLlmClient([
            ['thought' => 'looks right', 'action' => 'tool', 'tool' => 'submit_review',
                'arguments' => ['verdict' => 'accept', 'notes' => 'verified', 'confidence' => 0.7]],
        ]));

        $owner = $this->supervisor();
        $task = ResearchTask::create(['research_job_id' => $owner->id, 'seq' => 1, 'title' => 'T', 'brief' => 'b']);
        $reviewer = ResearchJob::create([
            'goal' => 'review it', 'role' => JobRole::Reviewer, 'status' => JobStatus::Running,
            'config' => ['review_task_id' => $task->id],
        ]);

        app(ResearchOrchestrator::class)->advance($reviewer->id);

        $reviewer->refresh();
        $this->assertSame(JobStatus::Completed, $reviewer->status);
        $this->assertSame('accept', $reviewer->review_verdict['verdict']);
        $this->assertEqualsWithDelta(0.7, $reviewer->confidence, 0.001);
    }

    public function test_availability_failure_hands_the_task_to_an_available_model(): void
    {
        // A retry that failed on an availability error (429) is routed off the dead
        // model onto an available tier's model — deterministically, no LLM.
        Queue::fake();
        config([
            'research.llm.tiers.hard.model' => 'cloud-model',
            'research.llm.tiers.standard.model' => 'local-model',
            'research.llm.tiers.light.model' => 'cloud-model', // also the dead model → must be skipped
        ]);
        // The single-model health probe for the replacement reports it up.
        Http::fake(['*/health*' => Http::response(['healthy_count' => 1, 'unhealthy_count' => 0])]);

        $job = $this->supervisor();
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Assemble', 'brief' => 'b',
            'status' => TaskStatus::Pending, 'tier' => 'hard', 'depends_on' => [], 'attempts' => 1,
            'result' => ResearchTask::WORKER_ERROR_PREFIX.'litellm.RateLimitError: 429 too many requests']);

        app(ResearchOrchestrator::class)->advance($job->id);

        $task = ResearchTask::where('research_job_id', $job->id)->where('seq', 1)->first();
        $this->assertSame('standard', $task->tier, 'the task was handed to the available tier');
        $this->assertSame(TaskStatus::InProgress, $task->status, 'and re-delegated immediately');
    }

    public function test_approach_failure_gets_a_corrective_guideline_for_the_retry(): void
    {
        // A NON-availability failure runs one bounded FailureDiagnosis turn; its
        // guidance is stored on the task and reaches the next worker's brief.
        Queue::fake();
        config(['research.supervisor.diagnose_failures' => true]);
        $this->app->instance(LlmClient::class, new FakeLlmClient([[
            'diagnosis' => 'the worker emitted a bare tool payload with no action wrapper',
            'decision' => 'retry_with_guidance',
            'guidance' => 'Respond with ONE JSON object wrapped in {"action":"tool",...}.',
        ]]));

        $job = $this->supervisor();
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'Write', 'brief' => 'b',
            'status' => TaskStatus::Pending, 'depends_on' => [], 'attempts' => 1,
            'result' => ResearchTask::WORKER_ERROR_PREFIX.'invalid_llm_response: not a JSON object with an action field']);

        app(ResearchOrchestrator::class)->advance($job->id);

        $task = ResearchTask::where('research_job_id', $job->id)->where('seq', 1)->first();
        $this->assertStringContainsString('action', (string) $task->retry_guidance, 'the corrective guideline was stored');
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

    public function test_accept_is_blocked_when_a_declared_output_is_missing(): void
    {
        // Sandbox reports the file isn't there (404) — the deliverable was never written.
        Http::fake(['*/read*' => Http::response(['error' => 'ENOENT'], 404)]);

        $job = $this->supervisor();
        $task = ResearchTask::create([
            'research_job_id' => $job->id, 'seq' => 1, 'title' => 'UI', 'brief' => 'build ui',
            'status' => TaskStatus::AwaitingReview, 'result' => 'done!', 'outputs' => ['ui/index.html'],
        ]);

        $res = app(ToolRegistry::class)->get('review_task')
            ->execute(new ToolArguments(['task' => 1, 'verdict' => 'accept']), $this->ctx($job));

        $this->assertFalse($res->success);
        $this->assertStringContainsString('not really there', $res->observation);
        $this->assertSame(TaskStatus::AwaitingReview, $task->refresh()->status); // NOT accepted
    }

    public function test_accept_succeeds_when_the_declared_output_really_exists(): void
    {
        Http::fake(['*/read*' => Http::response([
            'path' => 'ui/index.html',
            'content' => str_repeat('<div>real rendered content</div>', 5),
        ])]);

        $job = $this->supervisor();
        $task = ResearchTask::create([
            'research_job_id' => $job->id, 'seq' => 1, 'title' => 'UI', 'brief' => 'build ui',
            'status' => TaskStatus::AwaitingReview, 'result' => 'done!', 'outputs' => ['ui/index.html'],
        ]);

        $res = app(ToolRegistry::class)->get('review_task')
            ->execute(new ToolArguments(['task' => 1, 'verdict' => 'accept']), $this->ctx($job));

        $this->assertTrue($res->success);
        $this->assertSame(TaskStatus::Done, $task->refresh()->status);
    }

    public function test_accept_fails_open_when_sandbox_is_unreachable(): void
    {
        // Transport failure (not a 404) must NOT wedge review — we can't verify, so allow it.
        // A ConnectionException surfaces as a non-SandboxException \Throwable from read().
        $sandbox = new class extends SandboxClient
        {
            public function __construct() {}

            public function read(string $job, string $path): array
            {
                throw new \RuntimeException('connection refused');
            }
        };
        $tool = new ReviewTaskTool(new ArtifactChecks($sandbox));

        $job = $this->supervisor();
        $task = ResearchTask::create([
            'research_job_id' => $job->id, 'seq' => 1, 'title' => 'UI', 'brief' => 'build ui',
            'status' => TaskStatus::AwaitingReview, 'result' => 'done!', 'outputs' => ['ui/index.html'],
        ]);

        $res = $tool->execute(new ToolArguments(['task' => 1, 'verdict' => 'accept']), $this->ctx($job));

        $this->assertTrue($res->success);
        $this->assertSame(TaskStatus::Done, $task->refresh()->status);
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
