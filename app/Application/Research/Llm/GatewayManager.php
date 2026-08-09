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

    /**
     * ONE read of the gateway's model catalogue — the raw fact everything else is
     * derived from. `ok = false` means the catalogue could not be READ at all
     * (gateway down, or the master key rejected); it is deliberately distinct from
     * "read fine, and there are zero models", which is a legitimate fresh install.
     *
     * @return array{ok: bool, models: list<string>, error: ?string}
     */
    public function catalog(): array
    {
        try {
            $req = $this->http->timeout(4)->acceptJson();
            if (filled($key = config('services.openai_compatible.key'))) {
                $req = $req->withToken($key);
            }
            $res = $req->get($this->baseUrl().'/models');

            return [
                'ok' => $res->successful(),
                'models' => collect($res->json('data', []))
                    ->pluck('id')->filter()->map(fn ($id) => (string) $id)->values()->all(),
                // A rejected key looks identical to a dead service unless we say so.
                'error' => $res->successful() ? null : $this->readError($res->status(), $res->body()),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'models' => [], 'error' => $e->getMessage()];
        }
    }

    /** Reachability + the model names the gateway serves, for the dashboard. */
    public function status(): array
    {
        $c = $this->catalog();

        return $this->shape($this->baseUrl(), $c['ok'], $c['models'], $c['error']);
    }

    private function readError(int $status, string $body): string
    {
        $j = json_decode($body, true);
        $msg = is_array($j) ? (string) ($j['error']['message'] ?? '') : '';

        return $status.($msg !== '' ? ': '.mb_strimwidth($msg, 0, 160, '…') : '');
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
