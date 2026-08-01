<?php

return [

    // The LLM provider (Anthropic Messages API).
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
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
