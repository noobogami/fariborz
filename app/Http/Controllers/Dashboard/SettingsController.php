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
        return view('dashboard.settings', [
            // Configuration tab.
            'schema' => $this->settings->schema(),
            'values' => $this->settings->currentValues(),
            'overridden' => $this->settings->overriddenKeys(),

            // Ollama & Tools sections (folded in from the former /tools page).
            'status' => $this->ollama->status(),
            'models' => $this->ollama->models(),
            'running' => $this->ollama->running(),
            'browser' => $this->browser->status(),
            'sandbox' => $this->sandbox->status(),
            'gateway' => $this->gateway->status(),
            'tools' => $this->registry->definitions(),
            'skills' => CustomTool::latest()->get(),
            'llmDriver' => config('research.llm.driver'),
            'searchKeySet' => filled(config('services.tavily.key'))
                || filled(config('services.brave.key'))
                || filled(config('services.serpapi.key')),
        ]);
    }

    public function update(Request $request)
    {
        $this->settings->save($request->input('settings', []));

        return redirect()->route('settings')->with('status', 'Settings saved — they apply to the next research iteration.');
    }
}
