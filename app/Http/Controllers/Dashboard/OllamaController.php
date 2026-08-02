<?php

namespace App\Http\Controllers\Dashboard;

use App\Application\Research\Browser\BrowserClient;
use App\Application\Research\Browser\BrowserServiceException;
use App\Application\Research\Llm\GatewayManager;
use App\Application\Research\Ollama\OllamaManager;
use App\Application\Research\Sandbox\SandboxClient;
use App\Http\Controllers\Controller;
use App\Jobs\PullOllamaModelJob;
use App\Models\CustomTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OllamaController extends Controller
{
    public function __construct(
        private OllamaManager $ollama,
        private BrowserClient $browser,
        private SandboxClient $sandbox,
        private GatewayManager $gateway,
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
            'status' => $this->ollama->status(),
            'models' => $this->ollama->models(),
            'running' => $this->ollama->running(),
            'browser' => $this->browser->status(),
            'sandbox' => $this->sandbox->status(),
            'gateway' => $this->gateway->status(),
        ]);
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

    /** Kick off a background model pull. */
    public function pull(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['model' => ['required', 'string', 'max:120']]);

        PullOllamaModelJob::dispatch($data['model'])->onQueue(config('research.queue.name'));

        if ($request->wantsJson()) {
            return response()->json(['pulling' => $data['model']]);
        }

        return back()->with('status', "Pulling model \"{$data['model']}\" in the background — it will appear below when ready.");
    }
}
