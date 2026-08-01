<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The append-only TIMELINE of everything the agent did.
     *
     * This is the single source of truth for tracing. Ordered by `seq`, the
     * rows read as a narrative: started → thought → used tool → got answer →
     * asked human → ... → finished. A supervisor days later runs
     * `php artisan research:trace <id>` which reads exactly this table.
     *
     * It is intentionally denormalized: each row has a human-readable
     * `summary` (for the eye) AND a full `payload` (for machines/debugging).
     */
    public function up(): void
    {
        Schema::create('research_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('research_job_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('seq');                 // monotonic per job — the timeline order
            $t->unsignedInteger('iteration');           // which planning loop turn this belongs to
            $t->string('type')->index();                // EventType
            $t->string('level')->default('info');       // info|warning|error
            $t->text('summary');                        // one-line, human-readable
            $t->json('payload')->nullable();            // full detail (args, results, prompts, etc.)
            $t->unsignedInteger('duration_ms')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();

            $t->index(['research_job_id', 'seq']);
            $t->index(['research_job_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_events');
    }
};
