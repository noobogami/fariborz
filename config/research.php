<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Safety limits (per research job)
    |--------------------------------------------------------------------------
    | These are the hard stops the orchestrator enforces so a runaway agent
    | cannot loop forever or burn an unbounded amount of money.
    */
    'limits' => [
        'max_iterations' => env('RESEARCH_MAX_ITERATIONS', 40),
        'max_tool_calls' => env('RESEARCH_MAX_TOOL_CALLS', 60),
        'timeout_seconds' => env('RESEARCH_TIMEOUT_SECONDS', 3600), // wall clock for the whole job
        // How many times the SAME tool may fail before the agent is told to stop using it.
        'max_tool_failures' => env('RESEARCH_MAX_TOOL_FAILURES', 3),
        // How many consecutive invalid LLM responses before we fail the job.
        'max_parse_failures' => env('RESEARCH_MAX_PARSE_FAILURES', 3),
        // How many consecutive blocked/no-progress actions before we stop (a weak
        // model can otherwise choose a blocked action forever — the stall breaker).
        'max_stalls' => env('RESEARCH_MAX_STALLS', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supervisor / worker hierarchy
    |--------------------------------------------------------------------------
    | A supervised project decomposes its goal into a task list and delegates
    | each task to a focused worker sub-agent. Workers get a small budget (do one
    | thing, stop). The supervisor auto-extends its OWN budget as tasks complete
    | so a long project runs to a finished deliverable rather than stalling.
    */
    'supervisor' => [
        // Before planning, a supervisor spends ONE bounded LLM turn extracting the
        // user's real spec (constraints, ordering, deliverable) from the raw goal,
        // so the plan honors "deploy the UI first" / "spawn 100 agents" instead of
        // the weak model re-guessing intent every turn. Disable to plan immediately.
        'comprehension' => env('RESEARCH_SUPERVISOR_COMPREHENSION', true),
        'worker_max_iterations' => env('RESEARCH_WORKER_MAX_ITERATIONS', 20),
        'worker_max_tool_calls' => env('RESEARCH_WORKER_MAX_TOOL_CALLS', 25),
        // Each time the supervisor needs more room, top up its budget by this much.
        'auto_extend_iterations' => env('RESEARCH_SUPERVISOR_EXTEND', 30),
        // Safety ceiling: never auto-extend a supervisor past this many iterations.
        'max_iterations_ceiling' => env('RESEARCH_SUPERVISOR_CEILING', 400),
        // How deep sub-projects may nest (supervisor → sub-supervisor → …). Past
        // this, a "project" delegation is downgraded to a plain worker.
        'max_depth' => env('RESEARCH_SUPERVISOR_MAX_DEPTH', 3),
        // How many times ONE task may be delegated before the orchestrator stops
        // re-doing it. A weak worker can produce output the supervisor keeps
        // rejecting (revise → redo → revise …) forever; past this cap the task is
        // force-accepted as best-effort so the project can finish. Deterministic —
        // the escape does not depend on the weak model noticing it's stuck.
        'max_task_attempts' => env('RESEARCH_SUPERVISOR_MAX_TASK_ATTEMPTS', 3),

        // When a worker fails, run ONE bounded FailureDiagnosis turn to decide how
        // to retry — add a corrective guideline to the next worker, or re-run it
        // clean. Availability failures (rate-limit / gateway down) skip the LLM and
        // are handled deterministically (reassign to an available model). Off =
        // every failure is a plain clean retry (the older behaviour).
        'diagnose_failures' => env('RESEARCH_SUPERVISOR_DIAGNOSE_FAILURES', true),

        // Health-aware model assignment. Before a worker is (re)assigned a model,
        // the orchestrator skips any model in an availability cooldown and hands the
        // task to an available one. A model enters cooldown when a job of its own
        // fails with an availability error (rate-limit / timeout / 5xx / empty
        // completion) — learned from real failures, no polling. Seconds.
        'model_unavailable_cooldown' => env('RESEARCH_MODEL_UNAVAILABLE_COOLDOWN', 120),

        // Per-task REVIEWER agents. When a task finishes and passes the
        // deterministic pre-gate (its declared output(s) exist & are non-empty —
        // ArtifactChecks), the orchestrator spawns a dedicated Reviewer agent in a
        // fresh, tiny context to judge it, instead of routing every review through
        // the supervisor's own (ever-growing) transcript. Off = the old behaviour:
        // every AwaitingReview task is left for the supervisor's review_task turn.
        'reviewer_enabled' => env('RESEARCH_SUPERVISOR_REVIEWER_ENABLED', true),
        // Tier override for reviewers specifically. Blank = use the TASK's own
        // tier (the same model that did the work also judges it, by default).
        'reviewer_tier' => env('RESEARCH_SUPERVISOR_REVIEWER_TIER', ''),
        // A reviewer's budget: verifying is cheaper than producing, so this is
        // deliberately small — a handful of read/verify calls, then submit_review.
        'reviewer_max_iterations' => env('RESEARCH_SUPERVISOR_REVIEWER_MAX_ITERATIONS', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retries / backoff for tool execution
    |--------------------------------------------------------------------------
    */
    'retries' => [
        'tool_attempts' => env('RESEARCH_TOOL_ATTEMPTS', 3),
        'tool_backoff_base_ms' => env('RESEARCH_TOOL_BACKOFF_MS', 1000),
        'tool_backoff_max_ms' => env('RESEARCH_TOOL_BACKOFF_MAX_MS', 15000),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM
    |--------------------------------------------------------------------------
    */
    'llm' => [
        // The app speaks to ONE thing — the self-hosted LiteLLM gateway (driver
        // 'openai_compatible'), which routes to local Ollama AND cloud providers.
        // There are no per-provider drivers in the app anymore; this stays only so
        // the dashboard can label the active path.
        'driver' => 'openai_compatible',
        // The DEFAULT model, used when no tier override applies. It is a GATEWAY
        // model name (managed in Settings ▸ Tools ▸ Gateway models), e.g.
        // "local-standard", "local-fast", "gpt-4o", "claude", … (see config/litellm).
        'model' => env('RESEARCH_LLM_MODEL', 'local-standard'),
        'max_tokens' => env('RESEARCH_LLM_MAX_TOKENS', 4096),
        'temperature' => env('RESEARCH_LLM_TEMPERATURE', 0.2),
        // Keep at most this many transcript messages verbatim; older ones get summarized.
        // Lower this for local models with small context windows.
        'transcript_window' => env('RESEARCH_TRANSCRIPT_WINDOW', 40),

        /*
        | Capability tiers — per-task model routing WITHOUT hardcoding a model
        | per role. The supervisor tags each task it plans with a tier (a bounded
        | decision the LLM is good at — "how hard is THIS task?"); the worker for
        | that task then runs on the tier's model. Solo jobs and the supervisor's
        | own planning/review turns use `default_tier`.
        |
        | A tier whose `model` is EMPTY falls back to `research.llm.model` above —
        | so with every tier blank the system behaves exactly as before (single
        | model). Set a tier's model to take over routing: with the LiteLLM gateway
        | (driver = openai_compatible) that's a gateway model name — a LOCAL model
        | for `light` (e.g. `local-fast`) and a CLOUD one for `hard` (e.g. `claude`)
        | mixes offline + cloud per task. With driver = ollama they're Ollama tags.
        */
        'tiers' => [
            'light' => [
                'model' => env('RESEARCH_LLM_TIER_LIGHT', ''),
                'hint' => 'Simple, mechanical, low-stakes work: scaffolding files, formatting, trivial edits, short factual lookups. Fastest & cheapest.',
            ],
            'standard' => [
                'model' => env('RESEARCH_LLM_TIER_STANDARD', ''),
                'hint' => 'The default. Most build & research tasks: implement a feature, write a page/section, summarize a handful of sources.',
            ],
            'hard' => [
                'model' => env('RESEARCH_LLM_TIER_HARD', ''),
                'hint' => 'Complex reasoning: architecture, tricky multi-file logic, subtle debugging, careful review, or long-form writing. Strongest & most expensive.',
            ],
        ],
        // Tier used when a task has none, and for solo/supervisor turns.
        'default_tier' => env('RESEARCH_LLM_DEFAULT_TIER', 'standard'),

        // OpenAI-compatible gateway (used when driver = openai_compatible). Point
        // base_url at a self-hosted LiteLLM proxy (default below — routes to local
        // Ollama AND cloud providers), or at LocalAI / vLLM / OpenRouter. Provider
        // API keys (OpenAI/Anthropic/Gemini/DeepSeek) live in the GATEWAY's env,
        // not here — the app only holds the gateway's own key (services.
        // openai_compatible.key), which may be blank for a keyless local gateway.
        'openai_compatible' => [
            // In Docker this is http://litellm:4000/v1; natively the published port.
            'base_url' => env('LLM_GATEWAY_URL', 'http://localhost:4000/v1'),
            'request_timeout' => env('LLM_GATEWAY_TIMEOUT', 600), // local models can be slow
            // Constrain output to a JSON object (response_format=json_object). The
            // agent ALWAYS expects a JSON decision, and weak local models are
            // unreliable at "JSON only" without it — LiteLLM maps this to Ollama's
            // format:json. Disable only for an endpoint that rejects the param.
            'force_json' => env('LLM_GATEWAY_FORCE_JSON', true),
            // Optional attribution headers — used by OpenRouter, ignored elsewhere.
            'referer' => env('LLM_GATEWAY_REFERER', ''),
            'title' => env('LLM_GATEWAY_TITLE', 'Fariborz'),
        ],
        // (Ollama's connection is the GATEWAY's concern — set OLLAMA_BASE_URL on the
        // litellm service, not here. Fariborz no longer talks to Ollama directly.)
    ],

    /*
    |--------------------------------------------------------------------------
    | Headless browser service (Playwright)
    |--------------------------------------------------------------------------
    | Lets the agent use free web UIs — search engines + public pages — without
    | a paid search API. See services/browser and the browser_search/read_webpage
    | tools. In Docker this is http://browser:3000; natively it's the published
    | port (default 3001).
    */
    'browser' => [
        'base_url' => env('BROWSER_SERVICE_URL', 'http://localhost:3001'),
        'request_timeout' => env('BROWSER_TIMEOUT', 45), // headless nav can be slow
        'default_engine' => env('BROWSER_SEARCH_ENGINE', 'bing'), // bing (works keyless) | duckduckgo | google
    ],

    /*
    |--------------------------------------------------------------------------
    | Code sandbox (build & test software)
    |--------------------------------------------------------------------------
    | An isolated container the agent writes files to and runs commands in. It
    | never touches the host. In Docker this is http://sandbox:3000; natively the
    | published port (default 3002).
    */
    'sandbox' => [
        'base_url' => env('SANDBOX_SERVICE_URL', 'http://localhost:3002'),
        // A pool of ports published from the sandbox to the host, so a web app the
        // agent starts (bound to 0.0.0.0:<free port>) is viewable by the user at
        // http://localhost:<port>. The agent picks a free one via sandbox_info.
        'app_ports' => env('SANDBOX_APP_PORTS', '8090-8099'),
        // Must exceed the max command timeout (300) so the client doesn't drop
        // right as a long command returns; and the queue's retry_after (660) must
        // exceed AdvanceResearchJob::$timeout (600) which exceeds this.
        'request_timeout' => env('SANDBOX_TIMEOUT', 330),   // HTTP timeout to the service
        'command_timeout' => env('SANDBOX_COMMAND_TIMEOUT', 180), // default per-command timeout (s)
    ],

    /*
    |--------------------------------------------------------------------------
    | Human interaction
    |--------------------------------------------------------------------------
    */
    'human' => [
        // There is exactly ONE human overseeing the research, referred to by this
        // name everywhere (tool, prompts, UI).
        'name' => env('RESEARCH_HUMAN_NAME', 'Father'),
        // Max questions asked in a single batch when the human comes online.
        'max_batch' => env('RESEARCH_HUMAN_MAX_BATCH', 3),
        // A queued human question is considered "answered elsewhere" at/above this confidence.
        'auto_resolve_confidence' => env('RESEARCH_HUMAN_AUTO_RESOLVE', 0.75),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'name' => env('RESEARCH_QUEUE_NAME', 'research'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing / observability
    |--------------------------------------------------------------------------
    | Every meaningful thing the agent does is written to the research_events
    | timeline AND to the dedicated "research" log channel. This is what lets a
    | supervisor reconstruct the full path days later.
    */
    'trace' => [
        'log_channel' => env('RESEARCH_LOG_CHANNEL', 'research'),
        // Truncate long observations in the timeline summary (full copy stays in payload).
        'summary_max_chars' => env('RESEARCH_TRACE_SUMMARY_CHARS', 280),
        // Persist the exact prompt sent to the LLM each turn (verbose; great for debugging).
        'store_prompts' => env('RESEARCH_TRACE_STORE_PROMPTS', true),
        'store_raw_llm_output' => env('RESEARCH_TRACE_STORE_RAW_LLM', true),
    ],
];
