<?php

namespace Tests\Unit;

use App\Application\Research\Planner\ModelRouter;
use App\Models\ResearchJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModelRouterTest extends TestCase
{
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
        Http::fake(['*' => Http::response('down', 500)]);
    }

    /** Make the gateway serve exactly these model names. */
    private function gatewayServes(string ...$names): void
    {
        Cache::flush();
        Http::fake(['*/models' => Http::response([
            'data' => array_map(fn ($n) => ['id' => $n], $names),
        ])]);
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
}
