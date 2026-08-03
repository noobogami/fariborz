<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LiteLLM providers — power the "Add gateway model" form in the dashboard
    |--------------------------------------------------------------------------
    | You add models to the gateway from a FORM (Settings ▸ Tools ▸ Gateway
    | models): pick a provider, give the model your own NAME, and — for cloud
    | providers — paste an API key (stored encrypted in LiteLLM's DB). Local
    | Ollama models just need the installed tag + a name. Each `prefix` is what
    | LiteLLM expects in front of the model id (litellm_params.model). Blank
    | api key falls back to the gateway's own env var (`key_env`).
    */
    // `models` are SUGGESTIONS shown in the form's datalist — you can still type
    // any current id. They must be the provider's EXACT api model id (hyphens, no
    // spaces or display names): "gemini-flash-latest", NOT "gemini flash".
    'providers' => [
        'ollama' => ['label' => 'Ollama (local)', 'prefix' => 'ollama_chat/', 'local' => true],
        'openai' => ['label' => 'OpenAI', 'prefix' => 'openai/', 'key_env' => 'OPENAI_API_KEY',
            'models' => ['gpt-4o', 'gpt-4o-mini', 'o3-mini', 'o1']],
        'anthropic' => ['label' => 'Anthropic', 'prefix' => 'anthropic/', 'key_env' => 'ANTHROPIC_API_KEY',
            'models' => ['claude-3-5-sonnet-latest', 'claude-3-5-haiku-latest', 'claude-3-opus-latest']],
        'gemini' => ['label' => 'Google Gemini', 'prefix' => 'gemini/', 'key_env' => 'GEMINI_API_KEY',
            'models' => ['gemini-flash-latest', 'gemini-pro-latest', 'gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-pro']],
        'deepseek' => ['label' => 'DeepSeek', 'prefix' => 'deepseek/', 'key_env' => 'DEEPSEEK_API_KEY',
            'models' => ['deepseek-chat', 'deepseek-reasoner']],
    ],

    // Sensible default context for a new local (Ollama) model. Ollama's ~4k
    // default TRUNCATES the long agent prompts, so we default high (see CLAUDE.md).
    'ollama_default_num_ctx' => 16384,

    /*
    | Optional local models seeded by `php artisan litellm:seed` for a fresh
    | setup, so the tiers have something to point at before you touch the form.
    | (Cloud models are added from the form since they need your keys.)
    */
    'defaults' => [
        ['name' => 'local-fast', 'provider' => 'ollama', 'model' => 'qwen3:8b', 'num_ctx' => 16384, 'think' => false],
        ['name' => 'local-standard', 'provider' => 'ollama', 'model' => 'qwen3.5:latest', 'num_ctx' => 16384, 'think' => true],
        ['name' => 'local-hard', 'provider' => 'ollama', 'model' => 'qwen3.5:latest', 'num_ctx' => 16384, 'think' => true],
    ],
];
