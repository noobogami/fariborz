<?php

namespace App\Infrastructure\Research\Llm;

use App\Domain\Research\Contracts\LlmClient;
use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

/**
 * Local / offline LLM via Ollama (https://ollama.com). No API key, no network
 * beyond your own machine. Same LlmClient contract as AnthropicClient — the
 * orchestrator, planner, tools, and traces are all identical; only this adapter
 * differs.
 *
 * Uses Ollama's /api/chat endpoint with `format: json`, which constrains the
 * model to emit valid JSON. That matters a lot for smaller local models, which
 * are worse than frontier models at "respond with JSON only" — here the runtime
 * enforces it so our Decision contract holds.
 *
 * Run a model first, e.g.:
 *   ollama pull llama3.1
 *   ollama serve            # usually already running on :11434
 */
class OllamaClient implements LlmClient
{
    public function __construct(private Http $http) {}

    public function complete(string $system, array $messages, ?callable $onProgress = null): string
    {
        $config = config('research.llm.ollama');

        $payload = [
            'model' => config('research.llm.model'),
            'messages' => $this->buildMessages($system, $messages),
            'stream' => false,
            'keep_alive' => $config['keep_alive'] ?? '10m',
            'options' => [
                'temperature' => (float) config('research.llm.temperature'),
                'num_predict' => (int) config('research.llm.max_tokens'),
                'num_ctx' => (int) ($config['num_ctx'] ?? 8192),
            ],
        ];

        // Force structured JSON output when enabled (recommended for local models).
        if ($config['force_json'] ?? true) {
            $payload['format'] = 'json';
        }

        // Disable "thinking" for reasoning models (e.g. Qwen3) so the reply is
        // the JSON decision only, with no <think>…</think> wrapper to strip.
        if (array_key_exists('think', $config)) {
            $payload['think'] = (bool) $config['think'];
        }

        // When a progress sink is provided, stream so the UI can show the model's
        // reasoning building live. Otherwise take the simpler blocking path.
        if ($onProgress !== null) {
            $streamed = $this->stream($config, $payload, $onProgress);
            if ($streamed !== '') {
                return $streamed;
            }
            // Streaming yielded nothing (connection hiccup) — fall back below.
        }

        $response = $this->http
            ->baseUrl($config['base_url'] ?? 'http://localhost:11434')
            ->timeout((int) ($config['request_timeout'] ?? 300)) // local models can be slow
            ->acceptJson()
            ->retry(2, 2000, throw: false)
            ->post('/api/chat', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'Ollama request failed: '.$response->status().' '.$response->body()
                .' — is `ollama serve` running and the model pulled?'
            );
        }

        // Non-streaming chat responses put the text at message.content.
        $content = $response->json('message.content');

        if (! is_string($content) || $content === '') {
            throw new RuntimeException('Ollama returned an empty completion: '.$response->body());
        }

        return $content;
    }

    /**
     * Stream /api/chat as newline-delimited JSON, accumulating message.content
     * (the JSON answer) and message.thinking (reasoning-model chain-of-thought)
     * and feeding both to $onProgress (throttled) as
     * ['content' => …, 'thinking' => …] so a live "thinking" preview can be
     * surfaced. Returns the full completion, or '' on failure so the caller can
     * fall back to a blocking request.
     */
    private function stream(array $config, array $payload, callable $onProgress): string
    {
        $payload['stream'] = true;

        try {
            $response = $this->http
                ->baseUrl($config['base_url'] ?? 'http://localhost:11434')
                ->timeout((int) ($config['request_timeout'] ?? 300))
                ->withOptions(['stream' => true])
                ->post('/api/chat', $payload);

            if ($response->failed()) {
                return '';
            }

            $body = $response->toPsrResponse()->getBody();
            $content = '';
            $thinking = '';
            $buffer = '';
            $lastEmit = 0.0;

            while (! $body->eof()) {
                $chunk = $body->read(16384);
                if ($chunk === '') {
                    continue;
                }
                $buffer .= $chunk;

                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $nl));
                    $buffer = substr($buffer, $nl + 1);
                    if ($line === '') {
                        continue;
                    }

                    $obj = json_decode($line, true);
                    if (! is_array($obj)) {
                        continue;
                    }

                    $delta = $obj['message']['content'] ?? '';
                    if (is_string($delta) && $delta !== '') {
                        $content .= $delta;
                    }

                    // Reasoning models (Qwen3, etc.) stream their chain-of-thought
                    // on a SEPARATE `message.thinking` channel, distinct from the
                    // JSON answer in `message.content`. Capture it so the UI can
                    // narrate what the model is reasoning about right now.
                    $reason = $obj['message']['thinking'] ?? '';
                    if (is_string($reason) && $reason !== '') {
                        $thinking .= $reason;
                    }

                    // Throttle UI updates to a few per second.
                    $now = microtime(true);
                    if ($now - $lastEmit > 0.35) {
                        $onProgress(['content' => $content, 'thinking' => $thinking]);
                        $lastEmit = $now;
                    }

                    if (! empty($obj['done'])) {
                        $onProgress(['content' => $content, 'thinking' => $thinking]);

                        return $content;
                    }
                }
            }

            $onProgress(['content' => $content, 'thinking' => $thinking]);

            return $content;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Ollama's chat API takes the system prompt as a leading `system` message.
     * It is lenient about role alternation, but we still collapse consecutive
     * same-role turns to keep the context tidy and token-efficient.
     */
    private function buildMessages(string $system, array $messages): array
    {
        $out = [['role' => 'system', 'content' => $system]];

        foreach ($messages as $m) {
            $role = $m['role'] === 'assistant' ? 'assistant' : 'user';

            if (! empty($out) && end($out)['role'] === $role && $role !== 'system') {
                $out[count($out) - 1]['content'] .= "\n\n".$m['content'];

                continue;
            }

            $out[] = ['role' => $role, 'content' => $m['content']];
        }

        return $out;
    }
}
