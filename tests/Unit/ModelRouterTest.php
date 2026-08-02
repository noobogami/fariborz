<?php

namespace Tests\Unit;

use App\Application\Research\Planner\ModelRouter;
use App\Models\ResearchJob;
use Tests\TestCase;

class ModelRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'research.llm.model' => 'base-model',
            'research.llm.default_tier' => 'standard',
            'research.llm.tiers' => [
                'light' => ['model' => 'cheap-model', 'hint' => 'x'],
                'standard' => ['model' => '', 'hint' => 'y'],           // blank → falls back
                'hard' => ['model' => 'strong-model', 'hint' => 'z'],
            ],
        ]);
    }

    private function job(?string $tier): ResearchJob
    {
        return new ResearchJob(['config' => $tier === null ? [] : ['tier' => $tier]]);
    }

    public function test_a_tasks_tier_pins_that_tiers_model(): void
    {
        $applied = (new ModelRouter)->apply($this->job('hard'));

        $this->assertSame('strong-model', $applied);
        $this->assertSame('strong-model', config('research.llm.model'));
        $this->assertSame('hard', config('research.llm.active_tier'));
    }

    public function test_a_blank_tier_keeps_the_global_model(): void
    {
        $applied = (new ModelRouter)->apply($this->job('standard'));

        $this->assertNull($applied);
        $this->assertSame('base-model', config('research.llm.model'));
        $this->assertSame('standard', config('research.llm.active_tier'));
    }

    public function test_no_tier_uses_the_default_tier(): void
    {
        (new ModelRouter)->apply($this->job(null));

        $this->assertSame('standard', config('research.llm.active_tier'));
        $this->assertSame('base-model', config('research.llm.model'));
    }

    public function test_an_unknown_tier_falls_back_to_the_default(): void
    {
        (new ModelRouter)->apply($this->job('nonsense'));

        $this->assertSame('standard', config('research.llm.active_tier'));
        $this->assertSame('base-model', config('research.llm.model'));
    }
}
