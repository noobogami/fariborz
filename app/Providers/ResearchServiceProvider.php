<?php

namespace App\Providers;

use App\Application\Research\Guardrails\CancellationGuardrail;
use App\Application\Research\Guardrails\DuplicateActionGuardrail;
use App\Application\Research\Guardrails\GuardrailPipeline;
use App\Application\Research\Guardrails\MaxIterationsGuardrail;
use App\Application\Research\Guardrails\MaxToolCallsGuardrail;
use App\Application\Research\Guardrails\RepeatedFailureGuardrail;
use App\Application\Research\Guardrails\TimeoutGuardrail;
use App\Application\Research\Planner\LlmPlanner;
use App\Application\Research\Tools\ToolRegistry;
use App\Application\Research\Tracing\DbTraceRecorder;
use App\Console\Commands\SeedLiteLLMModelsCommand;
use App\Console\Commands\StartResearchCommand;
use App\Console\Commands\TraceResearchCommand;
use App\Domain\Research\Contracts\HumanQuestionRepository;
use App\Domain\Research\Contracts\LlmClient;
use App\Domain\Research\Contracts\MemoryRepository;
use App\Domain\Research\Contracts\Planner;
use App\Domain\Research\Contracts\ResearchJobRepository;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Events\HumanAvailabilityChanged;
use App\Events\HumanQuestionAnswered;
use App\Events\ResearchCompleted;
use App\Events\ResearchFailed;
use App\Infrastructure\Research\Llm\OpenAiCompatibleClient;
use App\Infrastructure\Research\Persistence\EloquentHumanQuestionRepository;
use App\Infrastructure\Research\Persistence\EloquentMemoryRepository;
use App\Infrastructure\Research\Persistence\EloquentResearchJobRepository;
use App\Infrastructure\Research\Tools\ArxivTool;
use App\Infrastructure\Research\Tools\AskHumanTool;
use App\Infrastructure\Research\Tools\BraveSearchTool;
use App\Infrastructure\Research\Tools\BrowserSearchTool;
use App\Infrastructure\Research\Tools\CalculatorTool;
use App\Infrastructure\Research\Tools\ContainerLogsTool;
use App\Infrastructure\Research\Tools\DelegateTaskTool;
use App\Infrastructure\Research\Tools\GoogleSearchTool;
use App\Infrastructure\Research\Tools\HackerNewsTool;
use App\Infrastructure\Research\Tools\KillProcessTool;
use App\Infrastructure\Research\Tools\ListFilesTool;
use App\Infrastructure\Research\Tools\ListProcessesTool;
use App\Infrastructure\Research\Tools\PlanTasksTool;
use App\Infrastructure\Research\Tools\ReadFileTool;
use App\Infrastructure\Research\Tools\ReadWebpageTool;
use App\Infrastructure\Research\Tools\ReviewTaskTool;
use App\Infrastructure\Research\Tools\RunCommandTool;
use App\Infrastructure\Research\Tools\RunCustomToolTool;
use App\Infrastructure\Research\Tools\SandboxInfoTool;
use App\Infrastructure\Research\Tools\SaveCustomToolTool;
use App\Infrastructure\Research\Tools\StackOverflowTool;
use App\Infrastructure\Research\Tools\StartServerTool;
use App\Infrastructure\Research\Tools\SubmitReviewTool;
use App\Infrastructure\Research\Tools\TavilySearchTool;
use App\Infrastructure\Research\Tools\WikipediaTool;
use App\Infrastructure\Research\Tools\WriteFileTool;
use App\Listeners\ReconcileQueueOnAvailability;
use App\Listeners\ResumeResearchOnHumanAnswer;
use App\Listeners\ResumeSupervisorOnChildDone;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ResearchServiceProvider extends ServiceProvider
{
    /**
     * ── Register a new TOOL here (one line) ──────────────────────────────────
     * Add the class to this list and it becomes available to the agent. That's
     * the entire extension surface — no other file changes.
     */
    private const TOOLS = [
        // ── Free, keyless tools ──────────────────────────────────────────────
        BrowserSearchTool::class,   // web search via headless browser
        ReadWebpageTool::class,     // read a public page in full
        WikipediaTool::class,       // encyclopedic summaries
        StackOverflowTool::class,   // programming Q&A (Stack Exchange API)
        HackerNewsTool::class,      // tech news & discussion (HN Algolia API)
        ArxivTool::class,           // academic papers (arXiv API)
        CalculatorTool::class,      // exact arithmetic
        AskHumanTool::class,        // async human escalation
        // ── Code sandbox (build & test software in an isolated container) ─────
        SandboxInfoTool::class,
        WriteFileTool::class,
        ReadFileTool::class,
        ListFilesTool::class,
        RunCommandTool::class,
        StartServerTool::class,     // start a long-lived server the right way
        ContainerLogsTool::class,
        ListProcessesTool::class,   // see what is running / hung
        KillProcessTool::class,     // unblock a stuck or runaway process
        // ── Self-authored skills (agent builds a tool, keeps it) ─────────────
        SaveCustomToolTool::class,
        RunCustomToolTool::class,
        // ── Supervisor control tools (role-gated: only supervisors see these) ─
        PlanTasksTool::class,
        DelegateTaskTool::class,
        ReviewTaskTool::class,
        // ── Reviewer control tool (role-gated: only reviewers see this) ───────
        SubmitReviewTool::class,
    ];

    /**
     * Key-gated search tools: each is registered ONLY if its API key is present,
     * so the agent never sees a tool that would 401. Keys go in config/services.
     *
     * @var array<string, class-string> configKey => Tool
     */
    private const KEYED_TOOLS = [
        'services.tavily.key' => TavilySearchTool::class,   // best for agents (free tier)
        'services.brave.key' => BraveSearchTool::class,    // good general search (free tier)
        'services.serpapi.key' => GoogleSearchTool::class,   // SerpAPI-backed google
    ];

    /** Guardrails participate simply by being listed here. */
    private const GUARDRAILS = [
        CancellationGuardrail::class,
        MaxIterationsGuardrail::class,
        MaxToolCallsGuardrail::class,
        TimeoutGuardrail::class,
        DuplicateActionGuardrail::class,
        RepeatedFailureGuardrail::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/research.php', 'research');

        // Contracts → implementations (swap any of these without touching callers).
        // EVERY model call goes through the self-hosted LiteLLM gateway (local
        // Ollama + cloud, behind one OpenAI-compatible endpoint). There is no
        // longer a per-provider driver in the app — the gateway does the routing.
        $this->app->bind(LlmClient::class, OpenAiCompatibleClient::class);
        $this->app->bind(Planner::class, LlmPlanner::class);
        $this->app->bind(TraceRecorder::class, DbTraceRecorder::class);
        $this->app->bind(ResearchJobRepository::class, EloquentResearchJobRepository::class);
        $this->app->bind(MemoryRepository::class, EloquentMemoryRepository::class);
        $this->app->bind(HumanQuestionRepository::class, EloquentHumanQuestionRepository::class);

        // Tag the always-on keyless tools + guardrails.
        $this->app->tag(self::TOOLS, 'research.tool');
        $this->app->tag(self::GUARDRAILS, 'research.guardrail');

        // NOT a singleton: rebuilt on each resolution so key-gated search tools
        // (Tavily/Brave/SerpAPI) appear/disappear the moment their key is set or
        // cleared in the UI — no restart. Cheap: the tools are simple objects.
        $this->app->bind(ToolRegistry::class, function ($app) {
            $tools = [];
            foreach ($app->tagged('research.tool') as $tool) {
                $tools[] = $tool;
            }
            foreach (self::KEYED_TOOLS as $configKey => $tool) {
                if (! empty(config($configKey))) {
                    $tools[] = $app->make($tool);
                }
            }

            return new ToolRegistry($tools);
        });

        $this->app->singleton(GuardrailPipeline::class, fn ($app) => new GuardrailPipeline(
            iterator_to_array($app->tagged('research.guardrail')),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                StartResearchCommand::class,
                TraceResearchCommand::class,
                SeedLiteLLMModelsCommand::class,
            ]);
        }

        // Event-driven human interaction — fully decoupled from the loop.
        Event::listen(HumanQuestionAnswered::class, ResumeResearchOnHumanAnswer::class);
        Event::listen(HumanAvailabilityChanged::class, ReconcileQueueOnAvailability::class);

        // A worker sub-agent finishing wakes its parked supervisor to review + advance.
        Event::listen(ResearchCompleted::class, [ResumeSupervisorOnChildDone::class, 'handleCompleted']);
        Event::listen(ResearchFailed::class, [ResumeSupervisorOnChildDone::class, 'handleFailed']);

        // Worker liveness heartbeat. The daemon fires Looping on EVERY poll — even
        // when idle with nothing queued — so refreshing the heartbeat here proves
        // "a worker is consuming the research queue" independently of whether a job
        // happens to be running. Without this, an idle worker's heartbeat expires
        // and the UI falsely reports "no worker / waiting for queue" even though the
        // backend is reachable and a worker is alive.
        $researchQueue = (string) config('research.queue.name', 'research');
        Event::listen(Looping::class, function (Looping $event) use ($researchQueue) {
            // $event->queue is the queue the worker is polling (may be a CSV or null).
            if ($event->queue === null || str_contains((string) $event->queue, $researchQueue)) {
                Cache::put('research:worker:last_seen', time(), 120);
            }
        });
    }
}
