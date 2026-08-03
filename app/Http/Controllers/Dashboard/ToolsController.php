<?php

namespace App\Http\Controllers\Dashboard;

use App\Application\Research\Browser\BrowserClient;
use App\Application\Research\Browser\BrowserServiceException;
use App\Application\Research\Llm\GatewayManager;
use App\Application\Research\Llm\LiteLLMAdminClient;
use App\Application\Research\Sandbox\SandboxClient;
use App\Http\Controllers\Controller;
use App\Models\CustomTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Tools operations page (under Settings): service status for the sandbox,
 * browser and the LLM gateway, plus gateway model management and saved skills.
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
        private GatewayManager $gateway,
        private LiteLLMAdminClient $litellm,
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
            'gateway' => $this->gateway->status(),
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

        return response()->json(['result' => $this->litellm->create($data['name'], $params), 'models' => $this->litellm->list()]);
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

        return response()->json(['name' => $name, 'result' => $result, 'models' => $this->litellm->list()]);
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
