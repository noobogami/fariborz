<?php

return [

    // The OpenAI-compatible LLM gateway's OWN key (e.g. a LiteLLM master key).
    // Blank for a keyless local gateway. Provider keys (OpenAI/Gemini/DeepSeek/…)
    // live in the gateway's config, not here.
    'openai_compatible' => [
        'key' => env('LLM_GATEWAY_KEY'),
    ],

    // Optional search backends. Each corresponding tool is registered ONLY when
    // its key is set, so the agent never wastes a turn on a 401.
    'serpapi' => [
        'key' => env('SERPAPI_KEY'),      // google_search  (SerpAPI, ~100/mo free)
    ],
    'tavily' => [
        'key' => env('TAVILY_API_KEY'),   // tavily_search  (AI-optimized, ~1000/mo free)
    ],
    'brave' => [
        'key' => env('BRAVE_API_KEY'),    // brave_search   (~2000/mo free)
    ],

];
