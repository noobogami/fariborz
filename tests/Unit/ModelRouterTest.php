<?php

namespace Tests\Unit;

use App\Application\Research\Planner\ModelRouter;
use App\Models\ModelBenchmark;
use App\Models\ResearchJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModelRouterTest extends TestCase
{
    use RefreshDatabase;

    /** Model names the fake gateway serves; null = catalogue unreadable. */
    private ?array $served = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'research.llm.model' => 'base-model',
            'research.llm.default_tier' => 'standard',
            'research.llm.tiers' => [
                'light' => ['model' => 'cheap-model', 'hint' => 'x'],
                'standard' => ['model' => '', 'hint' => 'y'],           // blank → falls back
                'hard' => ['model' => 'strong-model', 'hint' => 'z'],
            ],
        ]);

        // Default: the gateway is unreachable, so resolution fails open and the
        // configured names are used verbatim — the pre-catalogue behaviour.
        Http::preventStrayRequests();
        Http::fake(fn () => $this->served === null
            ? Http::response('down', 500)
            : Http::response(['data' => array_map(fn ($n) => ['id' => $n], $this->served)]));
    }

    /** Make the gateway serve exactly these model names. */
    private function gatewayServes(string ...$names): void
    {
        $this->served = $names;
        Cache::flush();
    }

    private function router(): ModelRouter
    {
        return app(ModelRouter::class);
    }

    private function job(?string $tier): ResearchJob
    {
        return new ResearchJob(['config' => $tier === null ? [] : ['tier' => $tier]]);
    }

    public function test_a_tasks_tier_pins_that_tiers_model(): void
    {
        $applied = $this->router()->apply($this->job('hard'));

        $this->assertSame('strong-model', $applied);
        $this->assertSame('strong-model', config('research.llm.model'));
        $this->assertSame('hard', config('research.llm.active_tier'));
    }

    public function test_a_blank_tier_uses_the_global_model(): void
    {
        $applied = $this->router()->apply($this->job('standard'));

        $this->assertSame('base-model', $applied);
        $this->assertSame('base-model', config('research.llm.model'));
        $this->assertSame('standard', config('research.llm.active_tier'));
    }

    public function test_no_tier_uses_the_default_tier(): void
    {
        $this->router()->apply($this->job(null));

        $this->assertSame('standard', config('research.llm.active_tier'));
        $this->assertSame('base-model', config('research.llm.model'));
    }

    public function test_an_unknown_tier_falls_back_to_the_default(): void
    {
        $this->router()->apply($this->job('nonsense'));

        $this->assertSame('standard', config('research.llm.active_tier'));
        $this->assertSame('base-model', config('research.llm.model'));
    }

    public function test_a_renamed_gateway_model_is_replaced_by_a_live_one(): void
    {
        // The operator renamed "strong-model" on the gateway; the saved tier setting
        // still names the old one. Sending it would 400 every turn.
        $this->gatewayServes('base-model', 'cheap-model');

        $this->assertSame('base-model', $this->router()->apply($this->job('hard')));
        $this->assertSame('base-model', config('research.llm.model'));
    }

    public function test_a_live_model_is_never_swapped(): void
    {
        $this->gatewayServes('base-model', 'cheap-model', 'strong-model');

        $this->assertSame('strong-model', $this->router()->apply($this->job('hard')));
    }

    public function test_an_unreachable_gateway_keeps_the_configured_model(): void
    {
        // Already the setUp fake: nothing readable → trust config, don't rewrite it.
        $this->assertSame('strong-model', $this->router()->apply($this->job('hard')));
    }

    public function test_a_blank_default_model_picks_one_from_the_gateway(): void
    {
        config(['research.llm.model' => '', 'research.llm.tiers.standard.model' => '']);
        $this->gatewayServes('only-model');

        $this->assertSame('only-model', $this->router()->apply($this->job('standard')));
    }

    public function test_an_empty_gateway_resolves_to_nothing(): void
    {
        config(['research.llm.model' => '']);
        $this->gatewayServes();

        $this->assertNull($this->router()->apply($this->job('standard')));
    }

    // ── §C — a `broken` model must never be routed to (fail-open) ──────────────

    public function test_a_tier_pointing_at_a_broken_model_routes_to_the_best_rated_live_model_instead(): void
    {
        $this->gatewayServes('strong-model', 'cheap-model', 'other-model');
        ModelBenchmark::create(['model' => 'strong-model', 'status' => 'done', 'score' => 90, 'rating' => 'broken']);
        ModelBenchmark::create(['model' => 'cheap-model', 'status' => 'done', 'score' => 40, 'rating' => 'weak']);
        ModelBenchmark::create(['model' => 'other-model', 'status' => 'done', 'score' => 85, 'rating' => 'strong']);

        // "strong-model" is what the hard tier is configured to — its NAME suggests
        // it's good, but the benchmark says it can't even produce a valid tool
        // call. "other-model" is the best live candidate that isn't broken.
        $this->assertSame('other-model', $this->router()->apply($this->job('hard')));
        $this->assertSame('other-model', config('research.llm.model'));
    }

    public function test_a_tier_pointing_at_a_weak_or_low_scoring_model_is_never_rerouted(): void
    {
        $this->gatewayServes('strong-model', 'cheap-model', 'other-model');
        ModelBenchmark::create(['model' => 'strong-model', 'status' => 'done', 'score' => 5, 'rating' => 'weak']);
        ModelBenchmark::create(['model' => 'other-model', 'status' => 'done', 'score' => 99, 'rating' => 'strong']);

        // A low score or a "weak" rating is the operator's own choice to keep or
        // change — only "broken" ever overrides it. A much better candidate
        // existing must NOT matter here.
        $this->assertSame('strong-model', $this->router()->apply($this->job('hard')));
    }

    public function test_no_benchmark_data_at_all_behaves_exactly_as_before_benchmarking_existed(): void
    {
        $this->gatewayServes('base-model', 'cheap-model', 'strong-model');

        $this->assertSame('strong-model', $this->router()->apply($this->job('hard')));
    }

    public function test_every_candidate_broken_keeps_the_configured_model_best_effort(): void
    {
        $this->gatewayServes('strong-model', 'cheap-model', 'base-model');
        ModelBenchmark::create(['model' => 'strong-model', 'status' => 'done', 'score' => 90, 'rating' => 'broken']);
        ModelBenchmark::create(['model' => 'cheap-model', 'status' => 'done', 'score' => 80, 'rating' => 'broken']);
        ModelBenchmark::create(['model' => 'base-model', 'status' => 'done', 'score' => 70, 'rating' => 'broken']);

        // Nothing else is any better — hand back the configured model rather than
        // crash or return an empty string.
        $this->assertSame('strong-model', $this->router()->apply($this->job('hard')));
    }

    public function test_a_broken_model_with_an_unreadable_catalogue_is_left_alone(): void
    {
        // No gatewayServes() call — the setUp fake keeps the catalogue unreachable.
        ModelBenchmark::create(['model' => 'strong-model', 'status' => 'done', 'score' => 90, 'rating' => 'broken']);

        // We know it's broken but can't confirm anything else is even live, so
        // rewriting the operator's config would be a guess, not a fact.
        $this->assertSame('strong-model', $this->router()->apply($this->job('hard')));
    }
}
