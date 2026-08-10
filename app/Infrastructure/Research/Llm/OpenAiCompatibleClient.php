<?php

namespace App\Infrastructure\Research\Llm;

use App\Application\Research\Llm\GatewayHealth;
use App\Domain\Research\Contracts\LlmClient;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * A driver for ANY OpenAI-compatible chat endpoint. Point it at a self-hosted
 * gateway — LiteLLM (recommended; routes to local Ollama AND cloud providers),
 * LocalAI, vLLM — or a hosted one like OpenRouter. The base URL + key are config,
 * so one class serves them all; nothing else in the app changes.
 *
 * This is how the agent gets a ROUTER: the gateway (e.g. LiteLLM) is fronted by a
 * single endpoint, and the model id we send — set per turn by ModelRouter from
 * the task's capability tier — selects which backend (a local model, or GPT /
 * Claude / Gemini / DeepSeek when online) actually runs it. Local models keep
 * working offline; cloud ones activate only when reachable and keyed.
 */
class OpenAiCompatibleClient implements LlmClient
{
    /**
     * GatewayHealth is nullable (default null) so OpenAiCompatibleClientTest's
     * plain `app(OpenAiCompatibleClient::class)` construction keeps working
     * unchanged — without a health tracker, adaptive retries simply never
     * trigger and this behaves exactly as before.
     */
    public function __construct(private Http $http, private ?GatewayHealth $health = null) {}

    public function complete(string $system, array $messages, ?callable $onProgress = null): string
    {
        $config = config('research.llm.openai_compatible');

        // ModelRouter resolves the model against the gateway's live catalogue, so a
        // blank one here means the catalogue itself had nothing to offer — the
        // gateway serves no models at all. Say that, rather than posting model:""
        // and surfacing whatever the gateway makes of it.
        $model = trim((string) config('research.llm.model', ''));
        if ($model === '') {
            throw new RuntimeException(
                'No model available: the LLM gateway serves none. Add one in Settings ▸ Tools ▸ Gateway models.'
            );
        }

        $payload = [
            'model' => $model,
            'messages' => $this->buildMessages($system, $messages),
            'max_tokens' => (int) config('research.llm.max_tokens'),
            'temperature' => (float) config('research.llm.temperature'),
            'stream' => false,
        ];

        // Constrain the reply to a JSON object so weak local models reliably honor
        // the Decision contract (LiteLLM maps this to Ollama's format:json). The
        // DecisionParser still validates; this just removes the "wrapped in prose"
        // failure mode. Unsupported params are dropped by the gateway.
        if ($config['force_json'] ?? true) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        // Stream when a progress sink is provided so the UI can show the model's
        // answer (and any reasoning) building live; otherwise take the blocking
        // path. Fall back to blocking if the stream yields nothing.
        if ($onProgress !== null) {
            $streamed = $this->stream($config, $payload, $onProgress);
            if ($streamed !== '') {
                return $streamed;
            }
        }

        // Against an already-struggling gateway, the normal 3-attempt client
        // retry just TRIPLES the load on the exact thing that's failing —
        // fighting the whole point of gateway_load control. So when the
        // gateway is strained (GatewayHealth::isStrained — slow or erroring),
        // drop to a single attempt with a longer backoff ceiling instead of
        // hammering it. research.gateway_load.adaptive_retries turns this off.
        $strained = (bool) config('research.gateway_load.adaptive_retries', true) && ($this->health?->isStrained() ?? false);
        $attempts = $strained ? 1 : 3;
        $maxBackoffMs = $strained ? 16000 : 8000;

        $response = $this->request($config)
            ->timeout((int) ($config['request_timeout'] ?? 120))
            ->retry($attempts, fn (int $attempt) => min(1000 * (2 ** $attempt), $maxBackoffMs), throw: false)
            ->post('/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException('LLM gateway request failed: '.$response->status().' '.$response->body());
        }

        $content = $response->json('choices.0.message.content');

        // An empty completion is NOT fatal. It happens when the model runs out of
        // output space — classically when a reasoning ("think") model burns the
        // whole remaining context window on `reasoning_content` and emits no answer
        // (finish_reason=length, content=""). Returning '' routes it through the
        // parser → the bounded invalid-decision guardrail, so the loop fails
        // gracefully (or retries) instead of crashing the entire job with an
        // uncaught RuntimeException. The HTTP-level failure above still throws.
        return is_string($content) ? $content : '';
    }

    /**
     * Stream /chat/completions as Server-Sent Events, accumulating each
     * `choices[0].delta.content` (the answer) and `choices[0].delta.reasoning`
     * (reasoning-model chain-of-thought, when the model emits one) and feeding
     * both to $onProgress (throttled) as ['content' => …, 'thinking' => …].
     * Returns the full completion, or '' on failure so complete() can fall back.
     */
    private function stream(array $config, array $payload, callable $onProgress): string
    {
        $payload['stream'] = true;

        try {
            $response = $this->request($config)
                ->timeout((int) ($config['request_timeout'] ?? 120))
                ->withOptions(['stream' => true])
                ->post('/chat/completions', $payload);

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

                // SSE frames are newline-delimited; we care only about `data:` lines.
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $nl));
                    $buffer = substr($buffer, $nl + 1);

                    // Skip blanks and `:`-prefixed keepalive comments (some gateways
                    // send `: OPENROUTER PROCESSING` / ping heartbeats).
                    if ($line === '' || $line[0] === ':' || ! str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $data = trim(substr($line, 5));
                    if ($data === '[DONE]') {
                        $onProgress(['content' => $content, 'thinking' => $thinking]);

                        return $content;
                    }

                    $obj = json_decode($data, true);
                    if (! is_array($obj)) {
                        continue;
                    }

                    $delta = $obj['choices'][0]['delta'] ?? [];

                    $piece = $delta['content'] ?? '';
                    if (is_string($piece) && $piece !== '') {
                        $content .= $piece;
                    }

                    // Reasoning models expose their chain-of-thought on a separate
                    // `reasoning` field, distinct from the `content` answer.
                    $reason = $delta['reasoning'] ?? '';
                    if (is_string($reason) && $reason !== '') {
                        $thinking .= $reason;
                    }

                    $now = microtime(true);
                    if ($now - $lastEmit > 0.35) {
                        $onProgress(['content' => $content, 'thinking' => $thinking]);
                        $lastEmit = $now;
                    }
                }
            }

            $onProgress(['content' => $content, 'thinking' => $thinking]);

            return $content;
        } catch (\Throwable) {
            return '';
        }
    }

    /** A pre-configured pending request: base URL + bearer auth + optional headers. */
    private function request(array $config): PendingRequest
    {
        $headers = ['Content-Type' => 'application/json'];

        // Bearer auth is optional — a keyless local gateway (e.g. LiteLLM with no
        // master key) needs none; a hosted one or a secured gateway does.
        if (! empty($key = config('services.openai_compatible.key'))) {
            $headers['Authorization'] = 'Bearer '.$key;
        }
        // Optional attribution headers (used by OpenRouter; ignored elsewhere).
        if (! empty($config['referer'])) {
            $headers['HTTP-Referer'] = $config['referer'];
        }
        if (! empty($config['title'])) {
            $headers['X-Title'] = $config['title'];
        }

        return $this->http
            ->baseUrl($config['base_url'] ?? 'http://localhost:4000/v1')
            ->withHeaders($headers);
    }

    /**
     * OpenAI-shaped chat: the system prompt is a leading system message, then the
     * transcript. Collapse consecutive same-role turns to keep it tidy — the API
     * is lenient about alternation but there's no reason to send redundant turns.
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
