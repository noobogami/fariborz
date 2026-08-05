<?php

namespace App\Application\Research\Llm;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * Talks to the LiteLLM gateway's ADMIN/management API (POST /model/new,
 * /model/delete, GET /model/info) — distinct from the OpenAI-compatible
 * completion API. Used to SEED Fariborz's model catalog (config/litellm.php)
 * into the gateway's database (store_model_in_db) so models are managed from
 * Fariborz instead of a static services/litellm/config.yaml. Requires the
 * gateway's master key. Defensive — a down gateway yields empty/failed results,
 * never an exception to the caller.
 */
class LiteLLMAdminClient
{
    public function __construct(private Http $http) {}

    /** The proxy ROOT — management endpoints live here, NOT under /v1. */
    private function root(): string
    {
        $base = rtrim((string) config('research.llm.openai_compatible.base_url', 'http://localhost:4000/v1'), '/');

        return preg_replace('#/v1$#', '', $base);
    }

    private function req(): PendingRequest
    {
        $r = $this->http->timeout(15)->acceptJson();
        if (filled($key = config('services.openai_compatible.key'))) {
            $r = $r->withToken($key);   // master key — management endpoints require it
        }

        return $r;
    }

    /** Model names the gateway currently serves. @return list<string> */
    public function names(): array
    {
        try {
            return collect($this->req()->get($this->root().'/v1/models')->json('data', []))
                ->pluck('id')->filter()->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** model_name => DB id (only DB-backed models have one). @return array<string,string> */
    public function idsByName(): array
    {
        try {
            $out = [];
            foreach ($this->req()->get($this->root().'/model/info')->json('data', []) as $m) {
                $name = $m['model_name'] ?? null;
                $id = $m['model_info']['id'] ?? null;
                if ($name && $id) {
                    $out[$name] = $id;
                }
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Create a model from a catalog entry's params.
     *
     * @param  array<string,mixed>  $params  litellm_params (model, api_base, num_ctx, …)
     * @return array{ok: bool, message: string}
     */
    public function create(string $name, array $params): array
    {
        try {
            $res = $this->req()->post($this->root().'/model/new', [
                'model_name' => $name,
                'litellm_params' => $params,
                // Every model Fariborz adds is a CHAT model. Pin the mode so
                // LiteLLM's health check probes chat (generateContent), not
                // embeddings (embedContent) — which it otherwise wrongly guesses
                // for newer aliases like gemini-flash-latest and reports a false 404.
                'model_info' => ['mode' => 'chat'],
            ]);

            return $res->successful()
                ? ['ok' => true, 'message' => 'created']
                : ['ok' => false, 'message' => 'failed ('.$res->status().'): '.$this->err($res->body())];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'error: '.$e->getMessage()];
        }
    }

    /** Delete a model by its DB id. @return array{ok: bool, message: string} */
    public function delete(string $id): array
    {
        try {
            $res = $this->req()->post($this->root().'/model/delete', ['id' => $id]);

            return $res->successful()
                ? ['ok' => true, 'message' => 'deleted']
                : ['ok' => false, 'message' => 'failed: '.$this->err($res->body())];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'error: '.$e->getMessage()];
        }
    }

    /**
     * Current gateway models with the id needed to delete each.
     *
     * @return list<array{name:string, id:?string}>
     */
    public function list(): array
    {
        $ids = $this->idsByName();

        return collect($this->names())
            ->map(fn ($n) => ['name' => $n, 'id' => $ids[$n] ?? null])
            ->values()->all();
    }

    /**
     * Turn a form submission (provider + model + options) into litellm_params.
     * Cloud providers get the pasted api_key (or fall back to the gateway's env
     * var); local Ollama models get api_base + num_ctx + think.
     *
     * @param  array<string,mixed>  $opts  api_key | api_base | num_ctx | think
     * @return array<string,mixed>
     */
    public function buildParams(string $provider, string $model, array $opts = []): array
    {
        $p = ((array) config('litellm.providers', []))[$provider] ?? null;
        if (! $p) {
            return [];
        }

        $params = ['model' => ($p['prefix'] ?? '').$model];

        if (! empty($p['local'])) {
            $params['api_base'] = ! empty($opts['api_base']) ? $opts['api_base'] : 'os.environ/OLLAMA_BASE_URL';
            $params['num_ctx'] = (int) ($opts['num_ctx'] ?? config('litellm.ollama_default_num_ctx', 16384));
            $params['think'] = (bool) ($opts['think'] ?? false);
        } elseif (! empty($p['key_env'])) {
            // A pasted key is stored (encrypted) in LiteLLM's DB; blank reuses the
            // gateway's own env var so the key never passes through Fariborz.
            $params['api_key'] = ! empty($opts['api_key']) ? $opts['api_key'] : 'os.environ/'.$p['key_env'];
        }

        return $params;
    }

    private function err(string $body): string
    {
        $j = json_decode($body, true);

        return (string) ($j['error']['message'] ?? mb_strimwidth($body, 0, 160, '…'));
    }

    /**
     * Run LiteLLM's /health — a REAL test call to every model (slow, and it spends
     * cloud-provider quota), so this is on-demand only, never polled. Returns a
     * per-model up/down list mapped back to your aliases, with a trimmed error.
     *
     * @return array{ok:bool, healthy:int, unhealthy:int, models:list<array{model:string,healthy:bool,error:?string}>, error?:string}
     */
    public function health(): array
    {
        try {
            $res = $this->req()->timeout(180)->get($this->root().'/health');
            $j = $res->json();
            $idToName = array_flip($this->idsByName());

            $label = fn ($e) => $idToName[$e['model_id'] ?? ''] ?? ($e['model'] ?? '?');
            $models = [];
            foreach ((array) ($j['healthy_endpoints'] ?? []) as $e) {
                $models[] = ['model' => $label($e), 'healthy' => true, 'error' => null];
            }
            foreach ((array) ($j['unhealthy_endpoints'] ?? []) as $e) {
                $models[] = ['model' => $label($e), 'healthy' => false, 'error' => $this->healthError((string) ($e['error'] ?? ''))];
            }
            usort($models, fn ($a, $b) => [$a['healthy'], $a['model']] <=> [$b['healthy'], $b['model']]);

            return [
                'ok' => $res->successful(),
                'healthy' => (int) ($j['healthy_count'] ?? 0),
                'unhealthy' => (int) ($j['unhealthy_count'] ?? 0),
                'models' => $models,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'healthy' => 0, 'unhealthy' => 0, 'models' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Probe a SINGLE model's availability — the cheap counterpart to health().
     * LiteLLM's /health accepts a ?model= filter, so this tests just one endpoint
     * instead of sweeping (and spending quota on) every model. Used on the hot
     * path to decide whether a model is usable before assigning it to an agent.
     * Fails OPEN (returns true) when the gateway itself can't be reached — an
     * infra outage must not wedge every assignment; only a model the gateway
     * REPORTS as unhealthy returns false.
     */
    public function isModelHealthy(string $model): bool
    {
        try {
            $res = $this->req()->timeout(30)->get($this->root().'/health', ['model' => $model]);
            if (! $res->successful()) {
                return true;   // gateway trouble — cannot disprove availability, fail open
            }

            $unhealthy = (int) ($res->json('unhealthy_count') ?? 0);

            return $unhealthy === 0;
        } catch (Throwable) {
            return true;   // unreachable — fail open
        }
    }

    /** Pull the human-readable bit out of LiteLLM's verbose error+stacktrace blob. */
    private function healthError(string $raw): string
    {
        $head = trim(preg_split('/\n?\s*stack trace:/i', $raw)[0] ?? $raw);
        // Prefer the provider's own JSON "message" if present.
        if (preg_match('/"message"\s*:\s*"([^"]+)"/', $head, $m)) {
            return trim($m[1]);
        }

        return mb_strimwidth($head, 0, 200, '…');
    }
}
