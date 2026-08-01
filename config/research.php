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
        'worker_max_iterations' => env('RESEARCH_WORKER_MAX_ITERATIONS', 20),
        'worker_max_tool_calls' => env('RESEARCH_WORKER_MAX_TOOL_CALLS', 25),
        // Each time the supervisor needs more room, top up its budget by this much.
        'auto_extend_iterations' => env('RESEARCH_SUPERVISOR_EXTEND', 30),
        // Safety ceiling: never auto-extend a supervisor past this many iterations.
        'max_iterations_ceiling' => env('RESEARCH_SUPERVISOR_CEILING', 400),
        // How deep sub-projects may nest (supervisor → sub-supervisor → …). Past
        // this, a "project" delegation is downgraded to a plain worker.
        'max_depth' => env('RESEARCH_SUPERVISOR_MAX_DEPTH', 3),
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
        // 'anthropic' (cloud) or 'ollama' (fully local/offline).
        'driver' => env('RESEARCH_LLM_DRIVER', 'anthropic'),
        // For anthropic: a model id like "claude-opus-4-8".
        // For ollama:    a pulled model tag like "llama3.1" or "qwen2.5".
        'model' => env('RESEARCH_LLM_MODEL', 'claude-opus-4-8'),
        'max_tokens' => env('RESEARCH_LLM_MAX_TOKENS', 4096),
        'temperature' => env('RESEARCH_LLM_TEMPERATURE', 0.2),
        // Keep at most this many transcript messages verbatim; older ones get summarized.
        // Lower this for local models with small context windows.
        'transcript_window' => env('RESEARCH_TRANSCRIPT_WINDOW', 40),

        // Local, offline inference via Ollama (used when driver = ollama).
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'num_ctx' => env('OLLAMA_NUM_CTX', 16384),     // context window in tokens
            'keep_alive' => env('OLLAMA_KEEP_ALIVE', '30m'),  // how long to keep the model loaded
            'request_timeout' => env('OLLAMA_TIMEOUT', 600),       // large local models can be slow
            // Constrain output to valid JSON — strongly recommended so local
            // models reliably honor the Decision contract.
            'force_json' => env('OLLAMA_FORCE_JSON', true),
            // Qwen3 (and other reasoning models) emit <think>…</think> blocks by
            // default, which break JSON parsing. Disable thinking so the reply is
            // the JSON decision only. Harmlessly ignored by non-thinking models.
            'think' => env('OLLAMA_THINK', false),
        ],
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
