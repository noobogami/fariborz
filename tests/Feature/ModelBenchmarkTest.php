<?php

namespace Tests\Feature;

use App\Application\Research\Llm\GatewayHealth;
use App\Application\Research\Llm\ModelAvailability;
use App\Application\Research\Llm\ModelBenchmark as ModelBenchmarkService;
use App\Domain\Research\Contracts\LlmClient;
use App\Jobs\BenchmarkModelJob;
use App\Models\ModelBenchmark as ModelBenchmarkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ModelBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // GatewayHealth is cache-backed (CACHE_STORE=array in phpunit.xml, which
        // persists for the whole test process) — start every test with a clean
        // window so an earlier test's samples never leak into this one's state().
        Cache::flush();
    }

    private function benchmark(): ModelBenchmarkService
    {
        return app(ModelBenchmarkService::class);
    }

    /**
     * A double that answers every probe correctly by reading what's actually
     * being asked (rather than a fixed script), so the same double works for
     * ping/envelope/reasoning/context in any order — including the context
     * probe, whose expected answer (a random code) is generated fresh on
     * every run and can't be pre-scripted.
     */
    private function perfectLlmClient(): LlmClient
    {
        return new class implements LlmClient
        {
            public function complete(string $system, array $messages, ?callable $onProgress = null): string
            {
                $content = $messages[0]['content'] ?? '';

                if (preg_match('/SECRET CODE:\s*(\S+)/', $content, $m)) {
                    return json_encode(['secret' => $m[1]]);
                }
                if (str_contains($content, 'Call the "note" tool')) {
                    return json_encode(['action' => 'tool', 'tool' => 'note', 'arguments' => ['text' => 'benchmark']]);
                }
                if (str_contains($content, 'must be written FIRST')) {
                    return json_encode(['first' => 'outline.md']);
                }

                return json_encode(['ok' => true]);
            }
        };
    }

    /** Everything correct EXCEPT the envelope probe, which gets bare tool args — no {"action":"tool",...} wrapper. */
    private function brokenEnvelopeLlmClient(): LlmClient
    {
        return new class implements LlmClient
        {
            public function complete(string $system, array $messages, ?callable $onProgress = null): string
            {
                $content = $messages[0]['content'] ?? '';

                if (str_contains($content, 'Call the "note" tool')) {
                    return json_encode(['tool' => 'note', 'arguments' => ['text' => 'benchmark']]);
                }
                if (str_contains($content, 'must be written FIRST')) {
                    return json_encode(['first' => 'outline.md']);
                }

                return json_encode(['ok' => true]);
            }
        };
    }

    public function test_all_probes_passing_scores_strong_with_a_median_and_persists_probes(): void
    {
        $this->app->instance(LlmClient::class, $this->perfectLlmClient());

        $result = $this->benchmark()->run('local-standard');

        $this->assertSame('done', $result['status']);
        $this->assertSame('strong', $result['rating']);
        $this->assertIsInt($result['score']);
        $this->assertGreaterThanOrEqual(80, $result['score']);
        $this->assertIsInt($result['median_ms']);
        $this->assertCount(3, $result['probes'], 'shallow run: ping, envelope, reasoning — no context');
        $this->assertTrue(collect($result['probes'])->every(fn ($p) => $p['ok']));
    }

    /**
     * REGRESSION (found on the first real run — every local model reported
     * "failed / empty completion"): a reasoning model spends its output budget
     * thinking BEFORE it answers, so a probe budgeted only for its answer comes
     * back finish_reason=length with content="". Measured here: ~400 tokens of
     * reasoning against a 6-token answer.
     *
     * The double answers only when the budget leaves room for that reasoning,
     * which is exactly what REASONING_RESERVE guarantees. Shrink the reserve
     * and this test fails the way the live gateway did.
     */
    public function test_a_reasoning_model_that_thinks_before_answering_still_benchmarks(): void
    {
        $reserveNeeded = 400;

        $this->app->instance(LlmClient::class, new class($this->perfectLlmClient(), $reserveNeeded) implements LlmClient
        {
            public function __construct(private LlmClient $inner, private int $reserveNeeded) {}

            public function complete(string $system, array $messages, ?callable $onProgress = null): string
            {
                // Everything the model emits — reasoning first, then the answer —
                // comes out of ONE budget. Not enough room for the thinking and
                // the caller gets nothing at all.
                if ((int) config('research.llm.max_tokens') < $this->reserveNeeded) {
                    return '';
                }

                return $this->inner->complete($system, $messages, $onProgress);
            }
        });

        $result = $this->benchmark()->run('reasoning-model');

        $this->assertSame('done', $result['status'], 'a reasoning model must not be reported as unreachable');
        $this->assertSame('strong', $result['rating']);
        $this->assertTrue(collect($result['probes'])->every(fn ($p) => $p['ok']));
    }

    public function test_bare_tool_args_fail_the_envelope_probe_and_rate_broken_regardless_of_score(): void
    {
        $this->app->instance(LlmClient::class, $this->brokenEnvelopeLlmClient());

        $result = $this->benchmark()->run('flaky-model');

        $this->assertSame('done', $result['status'], 'the model WAS reachable — only its envelope answer was wrong');
        $this->assertSame('broken', $result['rating']);
        $this->assertNull($result['suggested_tier'], 'a hint is never offered for a model that cannot drive the agent');

        $envelope = collect($result['probes'])->firstWhere('name', 'envelope');
        $this->assertFalse($envelope['ok']);
        $reasoning = collect($result['probes'])->firstWhere('name', 'reasoning');
        $this->assertTrue($reasoning['ok'], 'other probes still ran and were scored normally');
    }

    public function test_an_unreachable_model_fails_ping_skips_the_rest_and_the_run_is_marked_failed(): void
    {
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            public function complete(string $system, array $messages, ?callable $onProgress = null): string
            {
                throw new RuntimeException('connection refused');
            }
        });

        $result = $this->benchmark()->run('dead-model');

        $this->assertSame('failed', $result['status'], 'unreachable is a FAILED run, not a "broken" rating — we never even confirmed reachability');
        $this->assertStringContainsString('connection refused', (string) $result['error']);
        $this->assertCount(1, $result['probes'], 'envelope/reasoning/context never ran');
        $this->assertSame('ping', $result['probes'][0]['name']);
        $this->assertNull($result['score']);
        $this->assertNull($result['rating']);
    }

    public function test_a_slow_but_perfect_model_outscores_a_fast_but_broken_one(): void
    {
        $slowButPerfect = new class implements LlmClient
        {
            public function complete(string $system, array $messages, ?callable $onProgress = null): string
            {
                // A REAL (tiny) delay so the two runs' median_ms genuinely differ —
                // not 5 real minutes (that would make the suite unusable), just
                // enough to prove speed never overturns the reliability gap.
                usleep(30_000);
                $content = $messages[0]['content'] ?? '';
                if (str_contains($content, 'Call the "note" tool')) {
                    return json_encode(['action' => 'tool', 'tool' => 'note', 'arguments' => ['text' => 'benchmark']]);
                }
                if (str_contains($content, 'must be written FIRST')) {
                    return json_encode(['first' => 'outline.md']);
                }

                return json_encode(['ok' => true]);
            }
        };

        $this->app->instance(LlmClient::class, $slowButPerfect);
        $slow = $this->benchmark()->run('slow-perfect');

        $this->app->instance(LlmClient::class, $this->brokenEnvelopeLlmClient());
        $fast = $this->benchmark()->run('fast-broken');

        $this->assertSame('strong', $slow['rating']);
        $this->assertSame('broken', $fast['rating']);
        $this->assertGreaterThan($fast['median_ms'], $slow['median_ms']);
        $this->assertGreaterThan($fast['score'], $slow['score'], 'reliability dominates — being measurably slower still wins on being correct');
    }

    public function test_deep_runs_the_context_probe_and_the_weights_renormalise(): void
    {
        $this->app->instance(LlmClient::class, $this->perfectLlmClient());
        $shallow = $this->benchmark()->run('m', false);
        $this->assertCount(3, $shallow['probes']);
        $this->assertNull(collect($shallow['probes'])->firstWhere('name', 'context'));

        $this->app->instance(LlmClient::class, $this->perfectLlmClient());
        $deep = $this->benchmark()->run('m', true);
        $this->assertCount(4, $deep['probes']);
        $context = collect($deep['probes'])->firstWhere('name', 'context');
        $this->assertNotNull($context);
        $this->assertTrue($context['ok']);

        // Both are all-pass runs — if the weights did NOT renormalise, adding a
        // probe that never ran in the shallow case would (wrongly) look like a
        // partial failure and drag its score down relative to the deep run.
        $this->assertSame($shallow['score'], $deep['score']);
    }

    public function test_gateway_strain_during_the_run_marks_the_result_low_confidence(): void
    {
        config(['research.gateway_load.min_samples' => 1, 'research.gateway_load.slow_ms' => 1]);
        app(GatewayHealth::class)->record(5000, true); // avg 5000ms >= slow_ms(1) -> 'slow' -> isStrained()

        $this->app->instance(LlmClient::class, $this->perfectLlmClient());

        $result = $this->benchmark()->run('m');

        $this->assertTrue($result['low_confidence']);
    }

    public function test_post_marks_rows_queued_and_dispatches_a_serialized_chain(): void
    {
        Bus::fake();
        Http::fake([
            '*/v1/models' => Http::response(['data' => [['id' => 'local-standard'], ['id' => 'claude']]]),
            '*/model/info' => Http::response(['data' => []]),
        ]);

        $response = $this->postJson(route('gateway.benchmark.start'), []);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('queued', ModelBenchmarkRecord::where('model', 'local-standard')->value('status'));
        $this->assertSame('queued', ModelBenchmarkRecord::where('model', 'claude')->value('status'));

        // One job per model, in the gateway's order, all inside a SINGLE chain —
        // proof they're serialized (only the head is ever independently
        // dispatched; the rest ride along as `chained`). Only the HEAD job
        // carries ->onQueue() at dispatch time — the rest of the chain picks up
        // chainQueue when the real dispatcher advances it, not at build time.
        Bus::assertChained([
            (new BenchmarkModelJob('local-standard', false))->onQueue((string) config('research.queue.name')),
            new BenchmarkModelJob('claude', false),
        ]);
    }

    public function test_get_returns_rows_only_for_models_the_gateway_currently_serves(): void
    {
        Http::fake([
            '*/v1/models' => Http::response(['data' => [['id' => 'local-standard']]]),
            '*/model/info' => Http::response(['data' => []]),
        ]);
        ModelBenchmarkRecord::create(['model' => 'local-standard', 'status' => 'done', 'score' => 90, 'rating' => 'strong']);
        ModelBenchmarkRecord::create(['model' => 'removed-model', 'status' => 'done', 'score' => 50, 'rating' => 'usable']);

        $response = $this->getJson(route('gateway.benchmark.status'));

        $response->assertOk();
        $this->assertSame(['local-standard'], collect($response->json('rows'))->pluck('model')->all());
    }

    public function test_gateway_health_calibrates_slow_against_the_benchmarked_median_and_falls_back_without_data(): void
    {
        config(['research.gateway_load.min_samples' => 1, 'research.gateway_load.slow_ms' => 75000, 'research.gateway_load.slow_factor' => 2.5]);
        ModelBenchmarkRecord::create(['model' => 'local-standard', 'status' => 'done', 'score' => 90, 'rating' => 'strong', 'median_ms' => 90000]);

        $health = app(GatewayHealth::class);
        $health->record(90000, true, model: 'local-standard');
        $this->assertSame('normal', $health->state(), 'a 90s window is well within 2.5x its own benchmarked 90s median — not "slow"');

        $health->reset();
        $health->record(90000, true, model: 'unbenchmarked-model');
        $this->assertSame('slow', $health->state(), 'no benchmark data for this model -> falls back to the fixed slow_ms unchanged');
    }

    public function test_pick_replacement_prefers_the_higher_scored_candidate_and_skips_broken_ones(): void
    {
        Http::fake(['*/health*' => Http::response(['unhealthy_count' => 0])]);
        ModelBenchmarkRecord::create(['model' => 'local-fast', 'status' => 'done', 'score' => 40, 'rating' => 'weak']);
        ModelBenchmarkRecord::create(['model' => 'local-hard', 'status' => 'done', 'score' => 95, 'rating' => 'strong']);
        ModelBenchmarkRecord::create(['model' => 'gemini', 'status' => 'done', 'score' => 99, 'rating' => 'broken']);

        $picked = app(ModelAvailability::class)->pickReplacement(['local-fast', 'gemini', 'local-hard'], 'cloud-default');

        $this->assertSame('local-hard', $picked, 'higher score wins though listed last; "gemini" scored higher still but is broken, so it is skipped entirely');
    }

    // ── §B — "apply suggested tiers" preview + apply ────────────────────────────

    public function test_suggested_tier_proposal_picks_the_highest_scorer_skips_broken_and_leaves_gaps_unchanged(): void
    {
        config(['research.llm.tiers' => [
            'light' => ['model' => 'model-b', 'hint' => 'x'],   // already what the benchmark would pick
            'standard' => ['model' => '', 'hint' => 'y'],       // nothing suggests "standard" — must stay untouched
            'hard' => ['model' => 'local-hard', 'hint' => 'z'], // benchmark disagrees — a real change
        ]]);
        Http::fake([
            '*/v1/models' => Http::response(['data' => [
                ['id' => 'model-a'], ['id' => 'model-b'], ['id' => 'model-c'], ['id' => 'model-d'],
            ]]),
            '*/model/info' => Http::response(['data' => []]),
        ]);
        ModelBenchmarkRecord::create(['model' => 'model-a', 'status' => 'done', 'score' => 70, 'rating' => 'usable', 'suggested_tier' => 'light']);
        ModelBenchmarkRecord::create(['model' => 'model-b', 'status' => 'done', 'score' => 90, 'rating' => 'strong', 'suggested_tier' => 'light']);
        ModelBenchmarkRecord::create(['model' => 'model-c', 'status' => 'done', 'score' => 99, 'rating' => 'broken', 'suggested_tier' => 'light']);
        ModelBenchmarkRecord::create(['model' => 'model-d', 'status' => 'done', 'score' => 60, 'rating' => 'usable', 'suggested_tier' => 'hard']);

        $response = $this->getJson(route('gateway.benchmark.suggestions'));

        $response->assertOk();
        $tiers = $response->json('tiers');

        // "model-b" (90) beats "model-a" (70); "model-c" scores highest of all
        // (99) but is broken and must never win. It already matches the
        // configured value, so it's marked unchanged — the "already correct"
        // case the operator complaint was about.
        $this->assertSame('model-b', $tiers['light']['current']);
        $this->assertSame('model-b', $tiers['light']['proposed']);
        $this->assertSame(90, $tiers['light']['score']);
        $this->assertTrue($tiers['light']['unchanged']);

        // No candidate suggested "standard" — left alone, proposed stays null.
        $this->assertSame('', $tiers['standard']['current']);
        $this->assertNull($tiers['standard']['proposed']);
        $this->assertTrue($tiers['standard']['unchanged']);

        // "hard" disagrees with what's configured — a real, visible change.
        $this->assertSame('local-hard', $tiers['hard']['current']);
        $this->assertSame('model-d', $tiers['hard']['proposed']);
        $this->assertFalse($tiers['hard']['unchanged']);
    }

    public function test_applying_suggested_tiers_writes_settings_through_the_configuration_path_and_busts_the_catalogue_cache(): void
    {
        config(['research.llm.tiers' => [
            'light' => ['model' => '', 'hint' => 'x'],
            'standard' => ['model' => '', 'hint' => 'y'],
            'hard' => ['model' => '', 'hint' => 'z'],
        ]]);
        Http::fake([
            '*/v1/models' => Http::response(['data' => [['id' => 'model-a']]]),
            '*/model/info' => Http::response(['data' => []]),
        ]);
        ModelBenchmarkRecord::create(['model' => 'model-a', 'status' => 'done', 'score' => 90, 'rating' => 'strong', 'suggested_tier' => 'light']);

        // Prime the catalogue cache so we can prove apply() busts it, same key
        // ModelCatalog::names() writes to.
        Cache::put('research:gateway:catalog', ['stale-cached-name'], 30);

        $response = $this->postJson(route('gateway.benchmark.apply'), []);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(['research.llm.tiers.light.model'], $response->json('applied'));
        // Written through SettingsService::save() — a real `settings` row, the
        // SAME path the Configuration form's POST /settings uses.
        $this->assertDatabaseHas('settings', ['config_key' => 'research.llm.tiers.light.model', 'value' => 'model-a']);
        $this->assertNull(Cache::get('research:gateway:catalog'), 'applying a tier change must bust the catalogue cache');
    }

    public function test_applying_suggested_tiers_writes_nothing_when_every_proposal_is_already_correct(): void
    {
        config(['research.llm.tiers' => [
            'light' => ['model' => 'model-a', 'hint' => 'x'],
            'standard' => ['model' => '', 'hint' => 'y'],
            'hard' => ['model' => '', 'hint' => 'z'],
        ]]);
        Http::fake([
            '*/v1/models' => Http::response(['data' => [['id' => 'model-a']]]),
            '*/model/info' => Http::response(['data' => []]),
        ]);
        ModelBenchmarkRecord::create(['model' => 'model-a', 'status' => 'done', 'score' => 90, 'rating' => 'strong', 'suggested_tier' => 'light']);

        $response = $this->postJson(route('gateway.benchmark.apply'), []);

        $response->assertOk()->assertJsonPath('applied', []);
        $this->assertDatabaseMissing('settings', ['config_key' => 'research.llm.tiers.light.model']);
    }

    // ── §E — a benchmark must not outlive the config it measured ────────────────

    public function test_adding_a_gateway_model_deletes_any_stale_benchmark_row_for_that_name(): void
    {
        ModelBenchmarkRecord::create(['model' => 'local-fast', 'status' => 'done', 'score' => 90, 'rating' => 'strong']);

        Http::fake([
            '*/v1/models' => Http::response(['data' => [['id' => 'other-model']]]), // "local-fast" not yet re-added
            '*/model/new' => Http::response(['ok' => true]),
            '*/model/info' => Http::response(['data' => []]),
        ]);

        $response = $this->postJson(route('gateway.models.create'), [
            'name' => 'local-fast', 'provider' => 'ollama', 'model' => 'qwen3:8b',
        ]);

        $response->assertOk()->assertJsonPath('result.ok', true);
        // The row measured whatever WAS under this name before — a new model
        // added under the same name (even with different think/num_ctx) is not
        // what that row describes.
        $this->assertDatabaseMissing('model_benchmarks', ['model' => 'local-fast']);
    }

    public function test_removing_a_gateway_model_deletes_its_benchmark_row(): void
    {
        ModelBenchmarkRecord::create(['model' => 'local-fast', 'status' => 'done', 'score' => 90, 'rating' => 'strong']);

        Http::fake([
            '*/v1/models' => Http::response(['data' => [['id' => 'local-fast']]]),
            '*/model/info' => Http::response(['data' => [
                ['model_name' => 'local-fast', 'model_info' => ['id' => 'db-id-1']],
            ]]),
            '*/model/delete' => Http::response(['ok' => true]),
        ]);

        $response = $this->postJson(route('gateway.models.delete'), ['name' => 'local-fast']);

        $response->assertOk()->assertJsonPath('result.ok', true);
        $this->assertDatabaseMissing('model_benchmarks', ['model' => 'local-fast']);
    }
}
