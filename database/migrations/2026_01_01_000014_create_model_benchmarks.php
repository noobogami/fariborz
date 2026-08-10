<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per gateway model — evidence from ModelBenchmark's probe suite
 * (Settings ▸ Tools ▸ Gateway models ▸ Benchmark), not a guess. `model` is
 * UNIQUE because a benchmark result is a CURRENT fact about that model name,
 * upserted on every run rather than accumulating history.
 *
 * A model added or removed THROUGH Fariborz (ToolsController::gatewayCreate /
 * gatewayDelete) actively deletes this row for that name — a benchmark is
 * evidence about a specific model config (see the `think`/`num_ctx` footgun in
 * CLAUDE.md), and once that config changes the row no longer describes what's
 * actually running under the name. A model removed some OTHER way (directly in
 * the LiteLLM admin UI) just leaves a stale row behind — the UI only renders
 * rows for names the gateway currently serves, and a stale row is otherwise
 * harmless (nothing queries it by anything but `model`, which nothing else
 * will ever collide on again).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_benchmarks', function (Blueprint $t) {
            $t->id();
            $t->string('model')->unique();
            $t->string('status')->default('queued'); // queued|running|done|failed
            $t->unsignedTinyInteger('score')->nullable();   // 0-100
            $t->string('rating')->nullable();                // broken|weak|usable|strong
            $t->unsignedInteger('median_ms')->nullable();
            $t->string('suggested_tier')->nullable();        // light|standard|hard — a HINT, never auto-applied
            $t->json('probes')->nullable();                  // [{name, ok, ms, detail}, ...]
            $t->boolean('low_confidence')->default(false);   // gateway was strained during the run
            $t->text('error')->nullable();                   // set only on status=failed (ping unreachable)
            $t->timestamp('ran_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_benchmarks');
    }
};
