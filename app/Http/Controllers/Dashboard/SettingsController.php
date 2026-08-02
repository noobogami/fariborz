<?php

namespace App\Http\Controllers\Dashboard;

use App\Application\Research\Browser\BrowserClient;
use App\Application\Research\Llm\GatewayManager;
use App\Application\Research\Ollama\OllamaManager;
use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Tools\ToolRegistry;
use App\Application\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\CustomTool;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        private SettingsService $settings,
        private OllamaManager $ollama,
        private BrowserClient $browser,
        private SandboxClient $sandbox,
        private GatewayManager $gateway,
        private ToolRegistry $registry,
    ) {}

    public function index()
    {
        $gateway = $this->gateway->status();
        $values = $this->settings->currentValues();

        return view('dashboard.settings', [
            // Configuration tab. Model fields become live dropdowns off the gateway.
            'schema' => $this->withModelDropdowns($this->settings->schema(), $gateway['models'] ?? [], $values),
            'values' => $values,
            'overridden' => $this->settings->overriddenKeys(),

            // Ollama & Tools sections (folded in from the former /tools page).
            'status' => $this->ollama->status(),
            'models' => $this->ollama->models(),
            'running' => $this->ollama->running(),
            'browser' => $this->browser->status(),
            'sandbox' => $this->sandbox->status(),
            'gateway' => $gateway,
            'tools' => $this->registry->definitions(),
            'skills' => CustomTool::latest()->get(),
            'llmDriver' => config('research.llm.driver'),
            'searchKeySet' => filled(config('services.tavily.key'))
                || filled(config('services.brave.key'))
                || filled(config('services.serpapi.key')),
        ]);
    }

    /**
     * Turn model fields (flagged `dynamic => gateway_models`) into dropdowns
     * populated from the gateway's LIVE `/v1/models` list. Falls back to leaving
     * them as text inputs when the gateway is unreachable (empty list), so nothing
     * breaks offline. The currently-saved value is always kept selectable even if
     * it's no longer in the live list.
     *
     * @param  list<string>  $models  gateway model names
     * @param  array<string,mixed>  $values  current setting values keyed by config path
     */
    private function withModelDropdowns(array $schema, array $models, array $values): array
    {
        if (empty($models)) {
            return $schema;
        }

        foreach ($schema as &$fields) {
            foreach ($fields as &$f) {
                if (($f['dynamic'] ?? null) !== 'gateway_models') {
                    continue;
                }
                $options = ! empty($f['allow_blank']) ? [''] : [];
                $options = array_merge($options, $models);

                $current = (string) ($values[$f['key']] ?? '');
                if ($current !== '' && ! in_array($current, $options, true)) {
                    $options[] = $current;   // keep a stale/custom value selectable
                }

                $f['type'] = 'select';
                $f['options'] = array_values(array_unique($options));
            }
        }

        return $schema;
    }

    public function update(Request $request)
    {
        $this->settings->save($request->input('settings', []));

        return redirect()->route('settings')->with('status', 'Settings saved — they apply to the next research iteration.');
    }
}
