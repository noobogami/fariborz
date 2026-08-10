<?php

namespace App\Jobs;

use App\Application\Research\Llm\ModelBenchmark;
use App\Models\ModelBenchmark as ModelBenchmarkRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Benchmarks ONE gateway model and writes the result to its model_benchmarks
 * row. Always dispatched as part of a Bus::chain (see
 * ToolsController::benchmarkStart) so exactly one of these runs at a time —
 * benchmarking several models concurrently would measure gateway CONTENTION,
 * not the models, and would fight the adaptive load control (GatewayHealth)
 * this app already applies.
 *
 * A local qwen3-class model can take 1-2 minutes for the full probe suite,
 * hence the generous timeout. tries = 1 for the same reason as
 * AdvanceResearchJob: a probe call is a real, possibly side-effecting gateway
 * call, and the queue must never silently replay one — a failed probe already
 * degrades gracefully into ok:false inside ModelBenchmark, and a hard crash
 * here just leaves the row `failed` with the error for the operator to see
 * and re-trigger manually.
 *
 * Deliberately does NOT create a ResearchJob — a benchmark run must never
 * count as research work or show up in the jobs dashboard.
 */
class BenchmarkModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $model, public bool $deep = false) {}

    public function handle(ModelBenchmark $benchmark): void
    {
        $row = ModelBenchmarkRecord::firstOrNew(['model' => $this->model]);
        $row->status = 'running';
        $row->save();

        try {
            $result = $benchmark->run($this->model, $this->deep);
        } catch (Throwable $e) {
            // A crash in the harness itself (not a probe failure — ModelBenchmark
            // already turns those into ok:false) — record it and let the chain
            // move on to the next model regardless.
            $row->update(['status' => 'failed', 'error' => $e->getMessage(), 'ran_at' => now()]);

            return;
        }

        $row->update([
            'status' => $result['status'],
            'score' => $result['score'],
            'rating' => $result['rating'],
            'median_ms' => $result['median_ms'],
            'suggested_tier' => $result['suggested_tier'],
            'probes' => $result['probes'],
            'low_confidence' => $result['low_confidence'],
            'error' => $result['error'],
            'ran_at' => now(),
        ]);
    }

    public function tags(): array
    {
        return ['benchmark', "model:{$this->model}"];
    }
}
