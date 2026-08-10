<?php

namespace App\Http\Controllers\Dashboard;

use App\Application\Research\Browser\BrowserClient;
use App\Application\Research\Browser\BrowserServiceException;
use App\Application\Research\Llm\LiteLLMAdminClient;
use App\Application\Research\Llm\ModelCatalog;
use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Jobs\BenchmarkModelJob;
use App\Models\CustomTool;
use App\Models\ModelBenchmark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

/**
 * The Tools operations page (under Settings): service status for the sandbox,
 * browser and the LLM gateway, plus gateway model management, benchmarking
 * and saved skills.
 *
 * The app never talks to a model provider directly — every model call goes
 * through the LiteLLM gateway — so this controller only ever reaches the gateway
 * admin API (add/remove/health of gateway models), never Ollama or a cloud
 * provider itself.
 */
class ToolsController extends Controller
{
    public function __construct(
        private BrowserClient $browser,
        private SandboxClient $sandbox,
        private ModelCatalog $catalog,
        private LiteLLMAdminClient $litellm,
        private SettingsService $settings,
    ) {}

    /** Toggle whether a saved skill is marked for promotion into the core. */
    public function promoteSkill(string $id): RedirectResponse
    {
        $tool = CustomTool::findOrFail($id);
        $tool->update(['promoted' => ! $tool->promoted]);

        return back()->with('status', $tool->promoted ? "Marked \"{$tool->name}\" to build into core." : 'Unmarked.');
    }

    public function deleteSkill(string $id): RedirectResponse
    {
        CustomTool::whereKey($id)->delete();

        return back()->with('status', 'Skill deleted.');
    }

    /** Live status (used by the tools page to poll). */
    public function status(): JsonResponse
    {
        return response()->json([
            'browser' => $this->browser->status(),
            'sandbox' => $this->sandbox->status(),
            'gateway' => $this->catalog->gatewayStatus(),
            'gatewayModels' => $this->litellm->list(),
        ]);
    }

    /**
     * Add ONE model to the gateway from the "Add gateway model" form: a provider,
     * the underlying model id, a name you choose, and — for cloud — an API key.
     */
    public function gatewayCreate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'provider' => ['required', 'string', 'in:'.implode(',', array_keys((array) config('litellm.providers', [])))],
            // No whitespace: the model must be the provider's exact api id
            // ("gemini-flash-latest"), not a display name ("gemini flash").
            'model' => ['required', 'string', 'max:160', 'regex:/^\S+$/'],
            'api_key' => ['nullable', 'string', 'max:400'],
            'api_base' => ['nullable', 'string', 'max:200'],
            'num_ctx' => ['nullable', 'integer', 'min:512', 'max:262144'],
            'think' => ['nullable', 'boolean'],
        ], [
            'model.regex' => 'The model id must have no spaces — use the provider\'s exact id (e.g. "gemini-flash-latest", not "gemini flash").',
        ]);

        if (in_array($data['name'], $this->litellm->names(), true)) {
            return response()->json(['result' => ['ok' => false, 'message' => "\"{$data['name']}\" already exists — remove it or pick another name."], 'models' => $this->litellm->list()]);
        }

        $params = $this->litellm->buildParams($data['provider'], trim($data['model']), [
            'api_key' => $data['api_key'] ?? null,
            'api_base' => $data['api_base'] ?? null,
            'num_ctx' => $data['num_ctx'] ?? null,
            'think' => $request->boolean('think'),
        ]);

        $result = $this->litellm->create($data['name'], $params);
        if ($result['ok'] ?? false) {
            // §E: a name is not a fixed identity — the operator can add a model
            // under a name that used to point at something else (or the same
            // name with different params, e.g. `think` flipped). Either way any
            // existing benchmark row for this name no longer describes what's
            // actually running now, so it must not keep being shown as evidence.
            ModelBenchmark::where('model', $data['name'])->delete();
        }
        $this->catalog->forget();   // the catalogue just changed — don't serve a stale one

        return response()->json(['result' => $result, 'models' => $this->litellm->list()]);
    }

    /** Run LiteLLM's /health on demand (real calls to every model — slow). */
    public function gatewayHealth(): JsonResponse
    {
        return response()->json($this->litellm->health());
    }

    /** Remove a model from the gateway by name. */
    public function gatewayDelete(Request $request): JsonResponse
    {
        $name = (string) $request->input('name');
        $id = $this->litellm->idsByName()[$name] ?? null;

        $result = $id
            ? $this->litellm->delete($id)
            : ['ok' => false, 'message' => 'not a DB-backed model (nothing to delete)'];
        if ($result['ok'] ?? false) {
            ModelBenchmark::where('model', $name)->delete();   // §E — see gatewayCreate
        }
        $this->catalog->forget();

        return response()->json(['name' => $name, 'result' => $result, 'models' => $this->litellm->list()]);
    }

    /**
     * Kick off an async benchmark run — one model, or every model the gateway
     * currently serves when `model` is omitted. Marks the affected rows
     * `queued` immediately (so the UI shows movement right away) and dispatches
     * a Bus::chain — one BenchmarkModelJob per model, serialized — because
     * probing several models at once would measure gateway CONTENTION, not the
     * models, and would fight the adaptive load control (GatewayHealth) this
     * app already applies. A local model can take 1-2 minutes per probe suite,
     * so this MUST be asynchronous: a synchronous action here would hold a
     * PHP worker hostage for the length of the whole sweep.
     */
    public function benchmarkStart(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:160'],
            'deep' => ['nullable', 'boolean'],
        ]);

        $names = $this->litellm->names();
        $targets = filled($data['model'] ?? null)
            ? array_values(array_intersect($names, [$data['model']]))
            : $names;

        if (empty($targets)) {
            return response()->json(['ok' => false, 'message' => 'no matching gateway model', 'rows' => $this->benchmarkRows()]);
        }

        $deep = $request->boolean('deep');
        foreach ($targets as $name) {
            ModelBenchmark::updateOrCreate(['model' => $name], ['status' => 'queued', 'error' => null]);
        }

        Bus::chain(array_map(fn ($name) => new BenchmarkModelJob($name, $deep), $targets))
            ->onQueue((string) config('research.queue.name'))
            ->dispatch();

        return response()->json(['ok' => true, 'rows' => $this->benchmarkRows()]);
    }

    /** Polled by the UI (~3s) while any row is queued/running. */
    public function benchmarkStatus(): JsonResponse
    {
        return response()->json(['rows' => $this->benchmarkRows()]);
    }

    /**
     * §B — the "apply suggested tiers" PREVIEW: what applying benchmark
     * evidence would change, without changing anything. See suggestedTierDiff().
     */
    public function benchmarkSuggestions(): JsonResponse
    {
        return response()->json(['tiers' => $this->suggestedTierDiff()]);
    }

    /**
     * §B — the ONE place a benchmark result is allowed to change tier config,
     * and only because the operator clicked this. Writes through
     * SettingsService (same path the Configuration form uses — nothing here
     * bypasses overrides/casting/the `settings` table) and busts the catalogue
     * cache, exactly like the Configuration form's own save does.
     */
    public function benchmarkApply(Request $request): JsonResponse
    {
        $diff = $this->suggestedTierDiff();

        $input = [];
        foreach ($diff as $tier => $row) {
            if ($row['proposed'] !== null && ! $row['unchanged']) {
                $input["research.llm.tiers.$tier.model"] = $row['proposed'];
            }
        }

        if (! empty($input)) {
            $this->settings->save($input);
            $this->catalog->forget();   // tier models just changed — don't serve a stale catalogue read
        }

        return response()->json(['ok' => true, 'applied' => array_keys($input), 'tiers' => $this->suggestedTierDiff()]);
    }

    /**
     * Deterministic diff for §B: for each configured tier, the model already
     * pinned to it vs. the benchmark's proposal for that tier.
     *
     * Proposal rule (no LLM — plain evidence): among models rated better than
     * `broken`, group by `suggested_tier` (ModelBenchmark's own hint — see
     * App\Application\Research\Llm\ModelBenchmark::suggestedTier) and take the
     * HIGHEST SCORE per tier; a tier with no candidate is left unchanged
     * (`proposed` stays null). Candidates are walked in model-name order so a
     * score tie always resolves to the same model, regardless of DB row order.
     *
     * @return array<string, array{current:string, proposed:?string, score:?int, rating:?string, unchanged:bool}>
     */
    private function suggestedTierDiff(): array
    {
        $names = $this->litellm->names();
        $tiers = array_keys((array) config('research.llm.tiers', []));

        $candidates = ModelBenchmark::ratedBy($names)
            ->values()
            ->filter(fn ($row) => $row->status === 'done'
                && $row->rating !== null && $row->rating !== 'broken'
                && $row->suggested_tier !== null && in_array($row->suggested_tier, $tiers, true))
            ->sortBy('model')
            ->values();

        $bestByTier = [];
        foreach ($candidates as $row) {
            $best = $bestByTier[$row->suggested_tier] ?? null;
            if ($best === null || (int) $row->score > (int) $best->score) {
                $bestByTier[$row->suggested_tier] = $row;
            }
        }

        $diff = [];
        foreach ($tiers as $tier) {
            $current = trim((string) config("research.llm.tiers.$tier.model", ''));
            $best = $bestByTier[$tier] ?? null;
            $proposed = $best?->model;

            $diff[$tier] = [
                'current' => $current,
                'proposed' => $proposed,
                'score' => $best?->score,
                'rating' => $best?->rating,
                'unchanged' => $proposed === null || $proposed === $current,
            ];
        }

        return $diff;
    }

    /**
     * Rows for models the gateway CURRENTLY serves, in the gateway's own
     * order — a model removed from the gateway leaves a harmless stale row
     * behind that the UI should never render.
     *
     * @return list<array<string,mixed>>
     */
    private function benchmarkRows(): array
    {
        $names = $this->litellm->names();

        return ModelBenchmark::whereIn('model', $names)->get()
            ->sortBy(fn ($row) => array_search($row->model, $names, true))
            ->values()
            ->toArray();
    }

    /** Ad-hoc test of the free browser search, straight from the UI. */
    public function browserTest(Request $request): JsonResponse
    {
        $data = $request->validate(['query' => ['required', 'string', 'max:200']]);

        try {
            $result = $this->browser->search($data['query'], (string) config('research.browser.default_engine', 'duckduckgo'), 5);

            return response()->json(['ok' => true, 'results' => $result['results'] ?? []]);
        } catch (BrowserServiceException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }
}
