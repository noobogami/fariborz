<?php

use Monolog\Handler\NullHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => ['single'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        /*
        |----------------------------------------------------------------------
        | Research channel — the agent's own diary.
        |----------------------------------------------------------------------
        | A daily rotating file dedicated to the agent so its trace is not
        | drowned out by framework noise. Every line carries structured
        | context: job id, iteration, sequence number, and event type.
        |
        | Tail it live:   tail -f storage/logs/research-*.log
        | Grep one job:   grep '"job":"<uuid>"' storage/logs/research-*.log
        */
        'research' => [
            'driver' => 'daily',
            'path' => storage_path('logs/research.log'),
            'level' => env('RESEARCH_LOG_LEVEL', 'debug'),
            'days' => env('RESEARCH_LOG_RETENTION_DAYS', 30),
            'replace_placeholders' => true,
            // JSON-ish context is appended to every line for machine parsing.
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],
    ],
];
