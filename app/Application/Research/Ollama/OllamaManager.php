<?php

namespace App\Application\Research\Ollama;

use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Read/manage a local Ollama instance from the dashboard: check reachability,
 * list installed + running models, and trigger a pull. All calls are defensive
 * — if Ollama is down the UI shows "unreachable" rather than erroring.
 */
class OllamaManager
{
    public function __construct(private Http $http) {}

    private function baseUrl(): string
    {
        return rtrim((string) config('research.llm.ollama.base_url', 'http://localhost:11434'), '/');
    }

    /** High-level status for the dashboard header. */
    public function status(): array
    {
        try {
            $res = $this->http->timeout(4)->get($this->baseUrl().'/api/version');

            return [
                'reachable' => $res->successful(),
                'base_url' => $this->baseUrl(),
                'version' => $res->json('version'),
                'active_driver' => config('research.llm.driver'),
                'active_model' => config('research.llm.model'),
            ];
        } catch (Throwable $e) {
            return [
                'reachable' => false,
                'base_url' => $this->baseUrl(),
                'error' => $e->getMessage(),
                'active_driver' => config('research.llm.driver'),
                'active_model' => config('research.llm.model'),
            ];
        }
    }

    /** Installed models (GET /api/tags). */
    public function models(): array
    {
        try {
            return collect($this->http->timeout(6)->get($this->baseUrl().'/api/tags')->json('models', []))
                ->map(fn ($m) => [
                    'name' => $m['name'] ?? '',
                    'size' => $this->humanSize($m['size'] ?? 0),
                    'parameter_size' => data_get($m, 'details.parameter_size'),
                    'quantization' => data_get($m, 'details.quantization_level'),
                ])->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** Currently loaded/running models (GET /api/ps). */
    public function running(): array
    {
        try {
            return collect($this->http->timeout(4)->get($this->baseUrl().'/api/ps')->json('models', []))
                ->map(fn ($m) => ['name' => $m['name'] ?? '', 'size' => $this->humanSize($m['size'] ?? 0)])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Pull a model (POST /api/pull). This can take many minutes for large
     * models, so it is invoked from a queued job, not a web request.
     */
    public function pull(string $model): void
    {
        $this->http
            ->timeout(3600)
            ->post($this->baseUrl().'/api/pull', ['model' => $model, 'stream' => false]);
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 1).' '.$units[$i];
    }
}
