<?php

namespace App\Http\Controllers\Dashboard;

use App\Application\Research\Browser\BrowserClient;
use App\Application\Research\Llm\LiteLLMAdminClient;
use App\Application\Research\Llm\ModelCatalog;
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
        private BrowserClient $browser,
        private SandboxClient $sandbox,
        private ModelCatalog $catalog,
        private LiteLLMAdminClient $litellm,
        private ToolRegistry $registry,
    ) {}

    public function index()
    {
        $gateway = $this->catalog->gatewayStatus();
        $values = $this->settings->currentValues();

        // null = the catalogue could not be READ (gateway down / bad key), which is
        // not the same as a gateway that legitimately serves nothing yet.
        $models = ($gateway['reachable'] ?? false) ? array_values((array) ($gateway['models'] ?? [])) : null;

        return view('dashboard.settings', [
            // Configuration tab. Model fields become live dropdowns off the gateway.
            'schema' => $this->withModelDropdowns($this->settings->schema(), $models, $values),
            'values' => $values,
            'overridden' => $this->settings->overriddenKeys(),

            // Tools tab (service status + gateway model management).
            'browser' => $this->browser->status(),
            'sandbox' => $this->sandbox->status(),
            'gateway' => $gateway,
            'gatewayModels' => $this->litellm->list(),
            'gatewayProviders' => (array) config('litellm.providers', []),
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
     * populated from the gateway's LIVE `/v1/models` list, so the only names you
     * can pick are names that exist.
     *
     * Three distinct states, all of which used to collapse into "leave it as a text
     * box and say nothing":
     *  - $models === null → the catalogue is UNREADABLE (gateway down / bad key).
     *    Keep a free-text box so an offline operator can still type a name, and say
     *    why there's no list.
     *  - $models === []   → the gateway is up and serves NOTHING (fresh install).
     *    A dropdown of nothing is useless, so keep the box but state the actual
     *    problem and where to fix it — otherwise the page just looks broken.
     *  - non-empty        → a real dropdown. A saved value the gateway no longer
     *    serves (renamed/deleted model) stays selectable but is flagged `stale`,
     *    with a notice naming the model that will be used instead.
     *
     * `ui = select` forces the dropdown control: the view otherwise renders any
     * ≤3-option select as a segmented button row, which mangles model names and
     * turns the blank "use default" option into an invisible button.
     *
     * @param  list<string>|null  $models  gateway model names, null = unreadable
     * @param  array<string,mixed>  $values  current setting values keyed by config path
     */
    private function withModelDropdowns(array $schema, ?array $models, array $values): array
    {
        $fallback = $this->catalog->fallback();

        foreach ($schema as &$fields) {
            foreach ($fields as &$f) {
                if (($f['dynamic'] ?? null) !== 'gateway_models') {
                    continue;
                }

                $current = trim((string) ($values[$f['key']] ?? ''));
                $blankOk = ! empty($f['allow_blank']);

                if ($models === null) {
                    $f['notice'] = 'Gateway unreachable — no live model list. Type a name, or fix the gateway in Tools ▸ Gateway.';

                    continue;
                }

                if ($models === []) {
                    $f['notice'] = 'The gateway serves no models yet — add one in Tools ▸ Gateway models.'
                        .($current !== '' ? " Until then \"{$current}\" cannot run." : '');

                    continue;
                }

                $options = array_merge($blankOk ? [''] : [], $models);
                if ($current !== '' && ! in_array($current, $options, true)) {
                    $options[] = $current;   // keep a stale value visible instead of silently swapping it
                    $f['stale'] = $current;
                    $f['notice'] = "\"{$current}\" is not on the gateway (renamed or removed)"
                        .($fallback !== null ? " — jobs run on \"{$fallback}\" until you pick one." : '.');
                }

                $f['type'] = 'select';
                $f['ui'] = 'select';
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
