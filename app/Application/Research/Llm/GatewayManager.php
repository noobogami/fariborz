<?php

namespace App\Application\Research\Llm;

use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Read the OpenAI-compatible LLM gateway (LiteLLM et al.) for the dashboard:
 * is it reachable, and which models does it expose. Defensive — a down gateway
 * reports "unreachable" rather than throwing.
 *
 * GET {base}/models is the OpenAI-standard model catalogue, so this works against
 * LiteLLM, LocalAI, vLLM, or OpenRouter unchanged. The list is exactly the set of
 * model names a tier can be pointed at.
 */
class GatewayManager
{
    public function __construct(private Http $http) {}

    private function baseUrl(): string
    {
        return rtrim((string) config('research.llm.openai_compatible.base_url', 'http://localhost:4000/v1'), '/');
    }

    /** Reachability + the model names the gateway serves, for the dashboard. */
    public function status(): array
    {
        $base = $this->baseUrl();

        try {
            $req = $this->http->timeout(4)->acceptJson();
            if (filled($key = config('services.openai_compatible.key'))) {
                $req = $req->withToken($key);
            }
            $res = $req->get($base.'/models');

            $models = collect($res->json('data', []))
                ->pluck('id')->filter()->values()->all();

            return $this->shape($base, $res->successful(), $models);
        } catch (Throwable $e) {
            return $this->shape($base, false, [], $e->getMessage());
        }
    }

    /** @param  list<string>  $models */
    private function shape(string $base, bool $reachable, array $models, ?string $error = null): array
    {
        return array_filter([
            'reachable' => $reachable,
            'base_url' => $base,
            'model_count' => count($models),
            'models' => $models,
            // Whether the gateway is the CURRENTLY selected driver — so the UI can
            // show it prominently when in use, and quietly otherwise.
            'is_active_driver' => config('research.llm.driver') === 'openai_compatible',
            'active_driver' => config('research.llm.driver'),
            'active_model' => config('research.llm.model'),
            'error' => $error,
        ], fn ($v) => $v !== null);
    }
}
