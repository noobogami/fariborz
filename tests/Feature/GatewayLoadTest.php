<?php

namespace Tests\Feature;

use App\Application\Research\Llm\GatewayHealth;
use App\Application\Research\Llm\ModelCatalog;
use App\Application\Research\ResearchOrchestrator;
use App\Application\Research\StartResearch;
use App\Domain\Research\Contracts\LlmClient;
use App\Domain\Research\Enums\JobRole;
use App\Domain\Research\Enums\JobStatus;
use App\Domain\Research\Enums\TaskStatus;
use App\Infrastructure\Research\Llm\HealthTrackingLlmClient;
use App\Models\ResearchJob;
use App\Models\ResearchTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\FakeLlmClient;
use Tests\TestCase;

class GatewayLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same fake gateway catalogue as SupervisorTest: routeRetry() resolves
        // tier→model against it on every delegation, so it has to serve exactly
        // the names these tests configure or delegation itself would 400.
        config(['research.llm.catalog_ttl' => 0]);
        Http::fake(['*/v1/models' => fn () => Http::response([
            'data' => array_map(fn ($n) => ['id' => $n], app(ModelCatalog::class)->configured()),
        ])]);
    }

    protected function tearDown(): void
    {
        // Carbon::setTestNow is PROCESS-global, not per-test-app — leaving it
        // frozen would corrupt every timestamp (deadline_at, activity_updated_at
        // diffs, created_at) in every test that runs after this one.
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function health(): GatewayHealth
    {
        return app(GatewayHealth::class);
    }

    // ── GatewayHealth: state machine + AIMD budget ──────────────────────────

    public function test_unknown_state_with_too_few_samples_holds_the_base_limit(): void
    {
        config(['research.gateway_load.base_concurrency' => 3, 'research.gateway_load.min_samples' => 3]);
        $health = $this->health();

        $this->assertSame('unknown', $health->state());
        $this->assertSame(3, $health->concurrencyLimit());

        $health->record(500, true);
        $health->record(500, true);
        $this->assertSame('unknown', $health->state(), 'still below min_samples');
        $this->assertSame(3, $health->concurrencyLimit());
    }

    public function test_fast_samples_grow_the_limit_by_one_per_interval_capped_at_max(): void
    {
        config([
            'research.gateway_load.min_samples' => 1,
            'research.gateway_load.base_concurrency' => 3,
            'research.gateway_load.max_concurrency' => 5,
            'research.gateway_load.fast_ms' => 1000,
            'research.gateway_load.adjust_interval_seconds' => 20,
            'research.gateway_load.idle_reset_seconds' => 600,
        ]);
        $health = $this->health();
        Carbon::setTestNow(now());

        $health->record(100, true);
        $this->assertSame(3, $health->concurrencyLimit(), 'first read establishes the base at t0');

        Carbon::setTestNow(now()->addSeconds(21));
        $health->record(100, true);
        $this->assertSame(4, $health->concurrencyLimit(), '+1 after one interval of fast calls');

        Carbon::setTestNow(now()->addSeconds(21));
        $health->record(100, true);
        $this->assertSame(5, $health->concurrencyLimit(), '+1 again');

        Carbon::setTestNow(now()->addSeconds(21));
        $health->record(100, true);
        $this->assertSame(5, $health->concurrencyLimit(), 'capped at max_concurrency, does not keep growing');
    }

    public function test_within_one_interval_the_limit_does_not_move(): void
    {
        config([
            'research.gateway_load.min_samples' => 1,
            'research.gateway_load.base_concurrency' => 3,
            'research.gateway_load.fast_ms' => 1000,
            'research.gateway_load.adjust_interval_seconds' => 20,
        ]);
        $health = $this->health();
        Carbon::setTestNow(now());

        $health->record(100, true);
        $this->assertSame(3, $health->concurrencyLimit());

        Carbon::setTestNow(now()->addSeconds(5)); // well within the interval
        $health->record(100, true);
        $this->assertSame(3, $health->concurrencyLimit(), 'one stray fast sample must not whipsaw the limit');
    }

    public function test_slow_samples_halve_the_limit_floored_at_min_and_mark_strained(): void
    {
        config([
            'research.gateway_load.min_samples' => 1,
            'research.gateway_load.base_concurrency' => 4,
            'research.gateway_load.min_concurrency' => 1,
            'research.gateway_load.slow_ms' => 1000,
            'research.gateway_load.adjust_interval_seconds' => 20,
        ]);
        $health = $this->health();
        Carbon::setTestNow(now());

        $health->record(5000, true);
        $this->assertSame(4, $health->concurrencyLimit(), 'first-ever read establishes the base, budget adjustment not yet applied');
        // isStrained()/state() read the raw window directly and are NOT
        // interval-gated — only the BUDGET adjustment in concurrencyLimit() is.
        $this->assertTrue($health->isStrained());

        Carbon::setTestNow(now()->addSeconds(21));
        $health->record(5000, true);
        $this->assertSame(2, $health->concurrencyLimit(), 'first halving: 4 -> 2');
        $this->assertTrue($health->isStrained());

        Carbon::setTestNow(now()->addSeconds(21));
        $health->record(5000, true);
        $this->assertSame(1, $health->concurrencyLimit(), 'second halving: 2 -> 1');

        Carbon::setTestNow(now()->addSeconds(21));
        $health->record(5000, true);
        $this->assertSame(1, $health->concurrencyLimit(), 'floored at min_concurrency, never below 1');
    }

    public function test_high_error_rate_at_low_latency_is_degraded(): void
    {
        config([
            'research.gateway_load.min_samples' => 3,
            'research.gateway_load.error_rate_slow' => 0.34,
            'research.gateway_load.fast_ms' => 20000,
        ]);
        $health = $this->health();

        $health->record(100, true);
        $health->record(100, false, '429 too many requests');
        $health->record(100, false, '429 too many requests');

        $this->assertSame('degraded', $health->state(), 'errors dominate even though latency alone would read as fast');
        $this->assertTrue($health->isStrained());
    }

    public function test_idle_period_resets_the_limit_to_base(): void
    {
        config([
            'research.gateway_load.min_samples' => 1,
            'research.gateway_load.base_concurrency' => 3,
            'research.gateway_load.slow_ms' => 1000,
            'research.gateway_load.adjust_interval_seconds' => 10,
            'research.gateway_load.idle_reset_seconds' => 300,
        ]);
        $health = $this->health();
        Carbon::setTestNow(now());

        $health->record(5000, true);
        $this->assertSame(3, $health->concurrencyLimit());

        Carbon::setTestNow(now()->addSeconds(11));
        $health->record(5000, true);
        $this->assertSame(1, $health->concurrencyLimit(), 'halved while slow');

        // Quiet period longer than idle_reset_seconds — no new record() calls.
        Carbon::setTestNow(now()->addSeconds(301));
        $this->assertSame(3, $health->concurrencyLimit(), "a fresh start after a quiet period forgets yesterday's punishment");
    }

    // ── ResearchOrchestrator enforcement ─────────────────────────────────────

    public function test_orchestrator_holds_back_spawns_when_the_gateway_is_slow_and_parks_without_an_llm_call(): void
    {
        Queue::fake();
        config([
            'research.gateway_load.min_samples' => 1,
            'research.gateway_load.base_concurrency' => 3,
            'research.gateway_load.slow_ms' => 1000,
            'research.gateway_load.adjust_interval_seconds' => 10,
        ]);
        // A poisoned response: if the orchestrator ever consulted the model this
        // turn, DecisionParser would reject it and bump parse_failures — proof
        // the deferred-spawn path really spends no model call.
        $this->app->instance(LlmClient::class, new FakeLlmClient(['not-json — must never be read']));

        $health = $this->health();
        Carbon::setTestNow(now());
        $health->record(5000, true);
        $health->concurrencyLimit();   // establishes the base at t0
        Carbon::setTestNow(now()->addSeconds(11));
        $health->record(5000, true);
        $this->assertSame(1, $health->concurrencyLimit(), 'forced slow: limit halved to 1');

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        $job->update(['requirements' => ['restatement' => 'x']]); // skip comprehension
        foreach (range(1, 4) as $i) {
            ResearchTask::create(['research_job_id' => $job->id, 'seq' => $i, 'title' => "T{$i}", 'brief' => 'b', 'status' => TaskStatus::Pending, 'depends_on' => []]);
        }

        app(ResearchOrchestrator::class)->advance($job->id);

        $tasks = ResearchTask::where('research_job_id', $job->id)->orderBy('seq')->get();
        $this->assertSame(1, $tasks->where('status', TaskStatus::InProgress)->count(), 'exactly one worker spawned');
        $this->assertSame(3, $tasks->where('status', TaskStatus::Pending)->count(), 'the rest stay pending, not lost');

        $job->refresh();
        $this->assertSame(JobStatus::Running, $job->status);
        $this->assertSame('awaiting_worker', $job->current_activity);
        $this->assertSame(0, $job->parse_failures, 'the LLM was never consulted this turn');
    }

    public function test_orchestrator_spawns_every_ready_task_when_the_gateway_has_headroom(): void
    {
        // No regression of today's parallelism: with plenty of budget, every
        // independent ready task still starts in the same pass.
        Queue::fake();
        config([
            'research.gateway_load.base_concurrency' => 10,
            'research.gateway_load.max_concurrency' => 10,
        ]);

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        $job->update(['requirements' => ['restatement' => 'x']]);
        foreach (range(1, 4) as $i) {
            ResearchTask::create(['research_job_id' => $job->id, 'seq' => $i, 'title' => "T{$i}", 'brief' => 'b', 'status' => TaskStatus::Pending, 'depends_on' => []]);
        }

        app(ResearchOrchestrator::class)->advance($job->id);

        $inProgress = ResearchTask::where('research_job_id', $job->id)->where('status', TaskStatus::InProgress)->count();
        $this->assertSame(4, $inProgress);
    }

    public function test_per_supervisor_floor_still_spawns_one_when_the_global_budget_is_already_spent(): void
    {
        // The deadlock case: another project's worker already occupies the
        // WHOLE global budget. This supervisor has nothing of its own in
        // flight yet, so it must still get exactly one spawn — otherwise
        // nothing would ever wake it (ResumeSupervisorOnChildDone only fires
        // from ITS OWN children) and it would park forever.
        Queue::fake();
        config([
            'research.gateway_load.base_concurrency' => 1,
            'research.gateway_load.min_samples' => 999, // stays 'unknown' -> limit = base = 1
        ]);
        ResearchJob::create(['goal' => 'other project worker', 'role' => JobRole::Worker, 'status' => JobStatus::Running, 'config' => []]);

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        $job->update(['requirements' => ['restatement' => 'x']]);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 1, 'title' => 'A', 'brief' => 'b', 'status' => TaskStatus::Pending, 'depends_on' => []]);
        ResearchTask::create(['research_job_id' => $job->id, 'seq' => 2, 'title' => 'B', 'brief' => 'b', 'status' => TaskStatus::Pending, 'depends_on' => []]);

        app(ResearchOrchestrator::class)->advance($job->id);

        $this->assertSame(1, ResearchTask::where('research_job_id', $job->id)->where('status', TaskStatus::InProgress)->count(),
            'the per-supervisor floor still grants exactly one spawn');
        $this->assertSame(1, ResearchTask::where('research_job_id', $job->id)->where('status', TaskStatus::Pending)->count(),
            'the other stays deferred, not lost');
    }

    public function test_gateway_load_control_can_be_disabled(): void
    {
        // enabled=false must behave exactly like today: unlimited spawns.
        Queue::fake();
        config([
            'research.gateway_load.enabled' => false,
            'research.gateway_load.base_concurrency' => 1,
            'research.gateway_load.max_concurrency' => 1,
        ]);

        $job = app(StartResearch::class)->handle('project', [], JobRole::Supervisor);
        $job->update(['requirements' => ['restatement' => 'x']]);
        foreach (range(1, 4) as $i) {
            ResearchTask::create(['research_job_id' => $job->id, 'seq' => $i, 'title' => "T{$i}", 'brief' => 'b', 'status' => TaskStatus::Pending, 'depends_on' => []]);
        }

        app(ResearchOrchestrator::class)->advance($job->id);

        $this->assertSame(4, ResearchTask::where('research_job_id', $job->id)->where('status', TaskStatus::InProgress)->count());
    }

    // ── HealthTrackingLlmClient ───────────────────────────────────────────────

    public function test_health_tracking_client_records_a_failure_on_throw_and_still_throws(): void
    {
        $inner = new class implements LlmClient
        {
            public function complete(string $system, array $messages, ?callable $onProgress = null): string
            {
                throw new RuntimeException('gateway blew up');
            }
        };
        $health = $this->health();
        $client = new HealthTrackingLlmClient($inner, $health);

        $thrown = null;
        try {
            $client->complete('sys', []);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'the original exception must still propagate unchanged');
        $this->assertSame('gateway blew up', $thrown->getMessage());

        $snap = $health->snapshot();
        $this->assertSame(1, $snap['samples']);
        $this->assertSame(1.0, $snap['error_rate'], 'a throw is recorded as a failed sample');
    }

    public function test_health_tracking_client_treats_an_empty_completion_as_a_failed_sample(): void
    {
        $inner = new class implements LlmClient
        {
            public function complete(string $system, array $messages, ?callable $onProgress = null): string
            {
                return '';
            }
        };
        $health = $this->health();
        $client = new HealthTrackingLlmClient($inner, $health);

        $out = $client->complete('sys', []);

        $this->assertSame('', $out, 'the empty completion still reaches the caller unchanged');
        $snap = $health->snapshot();
        $this->assertSame(1, $snap['samples']);
        $this->assertSame(1.0, $snap['error_rate'], 'an empty completion counts as a failure signal, like ModelAvailability treats it');
    }

    public function test_health_tracking_client_records_a_successful_sample(): void
    {
        $inner = new class implements LlmClient
        {
            public function complete(string $system, array $messages, ?callable $onProgress = null): string
            {
                return '{"action":"finish"}';
            }
        };
        $health = $this->health();
        $client = new HealthTrackingLlmClient($inner, $health);

        $client->complete('sys', []);

        $snap = $health->snapshot();
        $this->assertSame(1, $snap['samples']);
        $this->assertSame(0.0, $snap['error_rate']);
    }
}
