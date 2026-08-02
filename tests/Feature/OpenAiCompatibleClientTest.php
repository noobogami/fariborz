<?php

namespace Tests\Feature;

use App\Infrastructure\Research\Llm\OpenAiCompatibleClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiCompatibleClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'research.llm.model' => 'local-standard',
            'research.llm.max_tokens' => 1234,
            'research.llm.temperature' => 0.3,
            'services.openai_compatible.key' => 'sk-gateway-test',
            'research.llm.openai_compatible' => [
                'base_url' => 'http://litellm:4000/v1',
                'request_timeout' => 30,
                'referer' => '',
                'title' => '',
            ],
        ]);
    }

    public function test_it_sends_an_openai_shaped_request_and_returns_the_content(): void
    {
        Http::fake([
            'litellm:4000/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => '{"action":"finish"}']]],
            ]),
        ]);

        $out = app(OpenAiCompatibleClient::class)->complete('SYS', [
            ['role' => 'user', 'content' => 'hello'],
        ]);

        $this->assertSame('{"action":"finish"}', $out);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'http://litellm:4000/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer sk-gateway-test')
                && $body['model'] === 'local-standard'
                && $body['max_tokens'] === 1234
                && $body['messages'][0] === ['role' => 'system', 'content' => 'SYS']
                && $body['messages'][1] === ['role' => 'user', 'content' => 'hello'];
        });
    }

    public function test_it_uses_whatever_model_config_holds(): void
    {
        // The ModelRouter pins the tier's model into config before the call; the
        // client just forwards it — here the tier routed a hard task to the cloud.
        config(['research.llm.model' => 'claude']);

        Http::fake([
            'litellm:4000/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
        ]);

        app(OpenAiCompatibleClient::class)->complete('SYS', [['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($request) => $request->data()['model'] === 'claude');
    }

    public function test_a_keyless_gateway_sends_no_authorization_header(): void
    {
        config(['services.openai_compatible.key' => null]);

        Http::fake([
            'litellm:4000/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
        ]);

        app(OpenAiCompatibleClient::class)->complete('SYS', [['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
    }

    public function test_it_throws_on_an_empty_completion(): void
    {
        Http::fake(['litellm:4000/*' => Http::response(['choices' => [['message' => ['content' => '']]]])]);

        $this->expectException(\RuntimeException::class);

        app(OpenAiCompatibleClient::class)->complete('SYS', [['role' => 'user', 'content' => 'hi']]);
    }
}
