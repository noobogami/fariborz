<?php

namespace Tests\Feature;

use App\Application\Research\Llm\LiteLLMAdminClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiteLLMAdminClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'research.llm.openai_compatible.base_url' => 'http://litellm:4000/v1',
            'services.openai_compatible.key' => 'sk-master',
        ]);
    }

    public function test_health_maps_to_aliases_and_trims_the_verbose_error(): void
    {
        Http::fake([
            '*/model/info' => Http::response(['data' => [
                ['model_name' => 'local-hard', 'model_info' => ['id' => 'idA']],
                ['model_name' => 'gemini', 'model_info' => ['id' => 'idB']],
            ]]),
            '*/health' => Http::response([
                'healthy_endpoints' => [['model' => 'ollama_chat/qwen3.5:latest', 'model_id' => 'idA']],
                'unhealthy_endpoints' => [[
                    'model' => 'gemini/gemini-flash-latest', 'model_id' => 'idB',
                    'error' => "litellm.NotFoundError: GeminiException - {\n  \"error\": {\n    \"message\": \"models/gemini-flash-latest is not found for embedContent\"\n  }\n}\n\nstack trace: Traceback (most recent call last):\n  File huge...",
                ]],
                'healthy_count' => 1, 'unhealthy_count' => 1,
            ]),
        ]);

        $h = app(LiteLLMAdminClient::class)->health();

        $this->assertTrue($h['ok']);
        $this->assertSame(1, $h['healthy']);
        $this->assertSame(1, $h['unhealthy']);

        $gemini = collect($h['models'])->firstWhere('model', 'gemini');
        $this->assertFalse($gemini['healthy']);                                  // mapped id → alias
        $this->assertStringContainsString('is not found', $gemini['error']);     // provider message kept
        $this->assertStringNotContainsString('Traceback', $gemini['error']);     // stack trace stripped

        $local = collect($h['models'])->firstWhere('model', 'local-hard');
        $this->assertTrue($local['healthy']);
    }

    public function test_build_params_adds_provider_prefix_and_keys(): void
    {
        $c = app(LiteLLMAdminClient::class);

        $this->assertSame(['model' => 'gemini/gemini-flash-latest', 'api_key' => 'k'],
            $c->buildParams('gemini', 'gemini-flash-latest', ['api_key' => 'k']));

        $ollama = $c->buildParams('ollama', 'qwen3:8b', ['num_ctx' => 16384, 'think' => true]);
        $this->assertSame('ollama_chat/qwen3:8b', $ollama['model']);
        $this->assertSame(16384, $ollama['num_ctx']);
        $this->assertTrue($ollama['think']);
    }
}
