<?php

namespace App\Infrastructure\Research\Llm;

use App\Domain\Research\Contracts\LlmClient;
use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

/**
 * Thin adapter over the Anthropic Messages API. Isolated behind LlmClient so
 * the rest of the system knows nothing about the provider — swap this class to
 * use OpenAI, a local model, or a fake in tests.
 */
class AnthropicClient implements LlmClient
{
    public function __construct(private Http $http) {}

    public function complete(string $system, array $messages, ?callable $onProgress = null): string
    {
        $response = $this->http
            ->baseUrl('https://api.anthropic.com')
            ->withHeaders([
                'x-api-key' => config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
            ->timeout(120)
            ->retry(3, fn (int $attempt) => min(1000 * (2 ** $attempt), 8000), throw: false)
            ->post('/v1/messages', [
                'model' => config('research.llm.model'),
                'max_tokens' => (int) config('research.llm.max_tokens'),
                'temperature' => (float) config('research.llm.temperature'),
                'system' => $system,
                'messages' => $this->normalize($messages),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('LLM request failed: '.$response->status().' '.$response->body());
        }

        return collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');
    }

    /**
     * The API requires strictly alternating user/assistant turns starting with
     * user. Collapse consecutive same-role messages so our richer internal
     * transcript is always accepted.
     */
    private function normalize(array $messages): array
    {
        $out = [];

        foreach ($messages as $m) {
            $role = $m['role'] === 'assistant' ? 'assistant' : 'user';

            if (! empty($out) && end($out)['role'] === $role) {
                $out[count($out) - 1]['content'] .= "\n\n".$m['content'];

                continue;
            }

            $out[] = ['role' => $role, 'content' => $m['content']];
        }

        // Must begin with a user turn.
        if (! empty($out) && $out[0]['role'] !== 'user') {
            array_unshift($out, ['role' => 'user', 'content' => '(context)']);
        }

        return $out;
    }
}
