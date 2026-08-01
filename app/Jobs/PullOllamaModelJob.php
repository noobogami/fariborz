<?php

namespace App\Jobs;

use App\Application\Research\Ollama\OllamaManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pulls an Ollama model in the background (can take minutes for large models).
 * The dashboard polls /api/tags to see when it appears.
 */
class PullOllamaModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public string $model) {}

    public function handle(OllamaManager $ollama): void
    {
        $ollama->pull($this->model);
    }
}
