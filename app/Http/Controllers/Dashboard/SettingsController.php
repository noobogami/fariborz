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
use App\Models\ModelBenchmark;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
            'gatewayModels' => $gatewayModels = $this->litellm->list(),
            'gatewayProviders' => (array) config('litellm.providers', []),
            // Seeds the benchmark chips on first paint; the Tools panel then
            // polls GET gateway.benchmark.status for live updates while a run
            // is in flight, same pattern as gatewayModels above.
            'benchmarks' => ModelBenchmark::whereIn('model', array_column($gatewayModels, 'name'))->get()->keyBy('model'),
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
     *    A dropdown whose only entry SAYS there is nothing to pick, rather than an
     *    empty-looking control the operator has to interpret.
     *  - non-empty        → a real dropdown. A saved value the gateway no longer
     *    serves (renamed/deleted model) stays selectable but is flagged `stale`,
     *    with a notice naming the model that will be used instead.
     *
     * A model field must ALWAYS carry an option for the empty value, with a label
     * that spells out what empty means here. The empty value is a real, and now
     * default, setting ("no model pinned — use the gateway's"), and a <select>
     * whose value matches no option renders as a blank box: the control looks
     * broken and silently misreports the setting as whatever sits at the top of the
     * list. Never let a model field render with nothing selected.
     *
     * `ui = select` forces the dropdown control: the view otherwise renders any
     * ≤3-option select as a segmented button row, which mangles model names and
     * turns the blank option into an invisible button.
     *
     * @param  list<string>|null  $models  gateway model names, null = unreadable
     * @param  array<string,mixed>  $values  current setting values keyed by config path
     */
    private function withModelDropdowns(array $schema, ?array $models, array $values): array
    {
        $fallback = $this->catalog->fallback();
        $ttlDays = max(1, (int) config('research.llm.benchmark_ttl_days', 14));

        foreach ($schema as &$fields) {
            foreach ($fields as &$f) {
                if (($f['dynamic'] ?? null) !== 'gateway_models') {
                    continue;
                }

                $current = trim((string) ($values[$f['key']] ?? ''));

                if ($models === null) {
                    $f['notice'] = 'Gateway unreachable — no live model list. Type a name, or fix the gateway in Tools ▸ Gateway.'
                        .($current === '' ? ' Nothing is pinned right now.' : '');

                    continue;
                }

                $f['type'] = 'select';
                $f['ui'] = 'select';

                if ($models === []) {
                    // Nothing to choose from. Say so IN the control — the operator
                    // must be able to read "no model available" off the page.
                    $f['blank_label'] = '— no models available on the gateway —';
                    $f['options'] = $current === '' ? [''] : ['', $current];
                    $f['stale'] = $current;
                    $f['notice'] = 'The gateway serves no models yet — nothing can run. Add one in Tools ▸ Gateway models.'
                        .($current !== '' ? " Until then \"{$current}\" cannot run." : '');

                    continue;
                }

                $options = array_merge([''], $models);
                // Every live option gets a benchmark rating in ITS LABEL (score,
                // rating, latency), because that's where a model is actually
                // CHOSEN — the whole point of this task. ratedBy() over the same
                // list the dropdown shows, one query per field.
                $rated = ModelBenchmark::ratedBy($models);
                $f['option_labels'] = collect($models)
                    ->mapWithKeys(fn ($m) => [$m => $this->benchmarkOptionLabel($m, $rated)])
                    ->all();

                if ($current !== '' && ! in_array($current, $options, true)) {
                    $options[] = $current;   // keep a stale value visible instead of silently swapping it
                    $f['stale'] = $current;
                    $f['notice'] = "\"{$current}\" is not on the gateway (renamed or removed)"
                        .($fallback !== null ? " — jobs run on \"{$fallback}\" until you pick one." : '.');
                } elseif ($current !== '') {
                    // The name is still live — the only other thing worth a notice
                    // is what the benchmark says ABOUT it: broken (can't drive the
                    // agent at all — see ModelRouter's fail-open reroute), never
                    // benchmarked, or benchmarked so long ago it may not describe
                    // the model anymore (see §E — a benchmark is invalidated when
                    // the model it measured changes).
                    $f['notice'] = $this->benchmarkNotice($current, $rated, $ttlDays) ?? '';
                }

                $f['blank_label'] = ! empty($f['allow_blank'])
                    ? '— use default model —'
                    : '— none pinned · uses '.($fallback !== null ? "\"{$fallback}\"" : 'a gateway model').' —';
                $f['options'] = array_values(array_unique($options));
            }
        }

        return $schema;
    }

    /**
     * The dropdown option label for ONE gateway model: its rating, score and
     * latency when it's been measured, else a plain "not benchmarked" — the
     * "did it even run?" confusion the spec's complaint was about, made
     * unambiguous right where the model is picked.
     */
    private function benchmarkOptionLabel(string $model, Collection $rated): string
    {
        $row = $rated->get($model);
        if ($row === null || $row->status !== 'done' || $row->score === null || $row->rating === null) {
            return "{$model} — not benchmarked";
        }

        $latency = $row->median_ms === null ? '' : ' · '.number_format($row->median_ms / 1000, 1).'s';

        return "{$model} — {$row->score} {$row->rating}{$latency}";
    }

    /**
     * A notice for the field's CURRENT value, specifically about its benchmark
     * — only when it's still a live gateway name (a renamed/removed name gets
     * its own notice above, which takes priority). Null when there's nothing
     * worth flagging (rated fine and recent, or never benchmarked and that's
     * already obvious from the option label alone — still worth a nudge here
     * since the notice is what's visible without opening the dropdown).
     */
    private function benchmarkNotice(string $model, Collection $rated, int $ttlDays): ?string
    {
        $row = $rated->get($model);

        if ($row !== null && $row->rating === 'broken') {
            return "\"{$model}\" is rated broken — it fails the tool-call envelope and cannot drive the agent. Jobs on this tier are automatically routed to another model until you pick a different one.";
        }

        if ($row === null || $row->status !== 'done') {
            return "\"{$model}\" hasn't been benchmarked yet — run one from Tools ▸ Gateway models to see how it performs.";
        }

        if ($row->ran_at !== null && $row->ran_at->lt(now()->subDays($ttlDays))) {
            return "\"{$model}\" was last benchmarked ".$row->ran_at->diffForHumans().' — results may no longer reflect it. Consider re-running the benchmark.';
        }

        return null;
    }

    public function update(Request $request)
    {
        $this->settings->save($request->input('settings', []));

        return redirect()->route('settings')->with('status', 'Settings saved — they apply to the next research iteration.');
    }
}
