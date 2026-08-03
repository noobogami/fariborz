<?php

namespace App\Application\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Runtime configuration edited from the dashboard.
 *
 * Each editable field maps to a Laravel config path, so once applied the whole
 * app reads UI-set values transparently via config(). Only DB/Redis/APP_KEY
 * live in .env (needed to boot); everything else is set here after startup.
 *
 * apply() is called at boot (web) and at the start of every research iteration
 * (worker), so changes take effect live — no restart.
 */
class SettingsService
{
    /**
     * The editable settings, grouped for the UI. `key` is the config path.
     * type: string|int|float|bool|select. secret: stored encrypted, write-only.
     */
    public function schema(): array
    {
        return [
            'LLM' => [
                ['key' => 'research.llm.model', 'label' => 'Default model', 'type' => 'string', 'dynamic' => 'gateway_models', 'help' => 'Used when no tier overrides it. A gateway model name — pick from the dropdown (managed in Tools ▸ Gateway models).'],
                ['key' => 'research.llm.temperature', 'label' => 'Temperature', 'type' => 'float'],
                ['key' => 'research.llm.max_tokens', 'label' => 'Max tokens', 'type' => 'int'],
                ['key' => 'research.llm.transcript_window', 'label' => 'Transcript window', 'type' => 'int', 'help' => 'Messages kept verbatim before summarizing'],
            ],
            'Model tiers — per-task routing (leave blank to use the default model)' => $this->tierFields(),
            'Gateway — LiteLLM (every model call routes through here)' => [
                ['key' => 'research.llm.openai_compatible.base_url', 'label' => 'Gateway URL', 'type' => 'string', 'help' => 'Self-hosted LiteLLM (routes to Ollama + cloud). e.g. http://localhost:4000/v1'],
                ['key' => 'services.openai_compatible.key', 'label' => 'Gateway key', 'type' => 'string', 'secret' => true, 'help' => 'The gateway\'s own key (LiteLLM master key). Provider keys live in the gateway, not here — add cloud models with their keys in Tools ▸ Gateway models.'],
            ],
            'Search APIs (optional — enable a tool by adding its key)' => [
                ['key' => 'services.tavily.key', 'label' => 'Tavily API key', 'type' => 'string', 'secret' => true, 'help' => 'Enables tavily_search (~1000/mo free)'],
                ['key' => 'services.brave.key', 'label' => 'Brave API key', 'type' => 'string', 'secret' => true, 'help' => 'Enables brave_search (~2000/mo free)'],
                ['key' => 'services.serpapi.key', 'label' => 'SerpAPI key', 'type' => 'string', 'secret' => true, 'help' => 'Enables google_search (~100/mo free)'],
            ],
            'Browser service' => [
                ['key' => 'research.browser.base_url', 'label' => 'Browser service URL', 'type' => 'string', 'help' => 'e.g. http://localhost:3001'],
                ['key' => 'research.browser.default_engine', 'label' => 'Search engine', 'type' => 'select', 'options' => ['bing', 'duckduckgo', 'google'], 'help' => 'bing works keyless; google/duckduckgo block headless — for real Google use a SerpAPI key above'],
            ],
            'Human' => [
                ['key' => 'research.human.name', 'label' => 'Human name', 'type' => 'string'],
            ],
            'Safety limits' => [
                ['key' => 'research.limits.max_iterations', 'label' => 'Max iterations', 'type' => 'int'],
                ['key' => 'research.limits.max_tool_calls', 'label' => 'Max tool calls', 'type' => 'int'],
                ['key' => 'research.limits.timeout_seconds', 'label' => 'Job timeout (seconds)', 'type' => 'int'],
                ['key' => 'research.limits.max_tool_failures', 'label' => 'Max tool failures', 'type' => 'int'],
                ['key' => 'research.limits.max_parse_failures', 'label' => 'Max invalid LLM responses', 'type' => 'int'],
            ],
        ];
    }

    /**
     * The per-task routing fields, derived from config('research.llm.tiers') so
     * they always match the tiers the planner offers. One model box per tier
     * (blank = fall back to the default model), plus which tier to use by default.
     */
    private function tierFields(): array
    {
        $tiers = (array) config('research.llm.tiers', []);
        $names = array_keys($tiers);

        $fields = [[
            'key' => 'research.llm.default_tier', 'label' => 'Default tier', 'type' => 'select',
            'options' => $names ?: ['standard'],
            'help' => 'Tier for solo jobs and a supervisor\'s own turns, and for tasks left untagged',
        ]];

        foreach ($tiers as $name => $meta) {
            $fields[] = [
                'key' => "research.llm.tiers.$name.model",
                'label' => ucfirst($name).' tier model',
                'type' => 'string',
                'dynamic' => 'gateway_models',   // controller turns this into a live dropdown
                'allow_blank' => true,           // blank = fall back to the default model
                'help' => trim((string) ($meta['hint'] ?? '')).' — blank = default model; gateway names e.g. local-fast, local-hard, gpt-4o, claude',
            ];
        }

        return $fields;
    }

    /** Flatten the schema keyed by config path. */
    public function fields(): array
    {
        $out = [];
        foreach ($this->schema() as $fields) {
            foreach ($fields as $f) {
                $out[$f['key']] = $f;
            }
        }

        return $out;
    }

    /** Merge stored settings into the live config. Safe before migration. */
    public function apply(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }
            $fields = $this->fields();
            foreach (Setting::all() as $s) {
                $type = $fields[$s->config_key]['type'] ?? 'string';
                config()->set($s->config_key, $this->cast($this->raw($s), $type));
            }
        } catch (Throwable) {
            // Never let settings loading break boot (e.g. DB unavailable).
        }
    }

    /**
     * Current values for the UI form. Secrets are returned as a boolean "is set"
     * — their actual value is never sent to the browser.
     */
    public function currentValues(): array
    {
        $values = [];
        foreach ($this->fields() as $key => $f) {
            $values[$key] = ! empty($f['secret'])
                ? filled(config($key))
                : config($key);
        }

        return $values;
    }

    /** Config paths that currently have a stored override (vs. .env/default). */
    public function overriddenKeys(): array
    {
        try {
            if (! Schema::hasTable('settings')) {
                return [];
            }

            return Setting::pluck('config_key')->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** Persist submitted settings. $input is keyed by config path. */
    public function save(array $input): void
    {
        foreach ($this->fields() as $key => $f) {
            $type = $f['type'];
            $secret = $f['secret'] ?? false;

            if ($type === 'bool') {
                $this->put($key, ! empty($input[$key]) ? '1' : '0', false);

                continue;
            }

            if ($secret) {
                $val = trim((string) ($input[$key] ?? ''));
                if ($val !== '') {                    // blank = keep existing secret
                    $this->put($key, Crypt::encryptString($val), true);
                }

                continue;
            }

            if (! array_key_exists($key, $input)) {
                continue;
            }
            $val = trim((string) $input[$key]);
            if ($val === '') {
                Setting::where('config_key', $key)->delete(); // revert to .env/default
            } else {
                $this->put($key, $val, false);
            }
        }
    }

    private function put(string $key, string $value, bool $secret): void
    {
        Setting::updateOrCreate(['config_key' => $key], ['value' => $value, 'secret' => $secret]);
    }

    private function raw(Setting $s): ?string
    {
        if (! $s->secret) {
            return $s->value;
        }
        try {
            return Crypt::decryptString((string) $s->value);
        } catch (Throwable) {
            return null; // key rotated / corrupt — treat as unset
        }
    }

    private function cast(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true),
            default => $value,
        };
    }
}
