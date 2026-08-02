<?php

namespace Tests\Feature;

use App\Application\Research\Llm\GatewayManager;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GatewayManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'research.llm.driver' => 'openai_compatible',
            'research.llm.model' => 'local-standard',
            'research.llm.openai_compatible.base_url' => 'http://litellm:4000/v1',
            'services.openai_compatible.key' => 'sk-gateway',
        ]);
    }

    public function test_it_reports_reachable_with_the_model_catalogue(): void
    {
        Http::fake([
            'litellm:4000/v1/models' => Http::response(['data' => [
                ['id' => 'local-standard'], ['id' => 'claude'], ['id' => 'gpt-4o'],
            ]]),
        ]);

        $status = app(GatewayManager::class)->status();

        $this->assertTrue($status['reachable']);
        $this->assertSame(3, $status['model_count']);
        $this->assertSame(['local-standard', 'claude', 'gpt-4o'], $status['models']);
        $this->assertTrue($status['is_active_driver']);
        $this->assertSame('local-standard', $status['active_model']);

        // The gateway's own key is sent as a bearer token to /models.
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk-gateway'));
    }

    public function test_a_down_gateway_reports_unreachable_without_throwing(): void
    {
        Http::fake(['litellm:4000/*' => Http::response('nope', 500)]);

        $status = app(GatewayManager::class)->status();

        $this->assertFalse($status['reachable']);
        $this->assertSame(0, $status['model_count']);
        $this->assertSame([], $status['models']);
    }

    public function test_is_active_driver_is_false_when_another_driver_is_selected(): void
    {
        config(['research.llm.driver' => 'ollama']);
        Http::fake(['litellm:4000/*' => Http::response(['data' => []])]);

        $status = app(GatewayManager::class)->status();

        $this->assertFalse($status['is_active_driver']);
        $this->assertSame('ollama', $status['active_driver']);
    }
}
