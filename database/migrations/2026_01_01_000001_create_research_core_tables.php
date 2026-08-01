<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The top-level goal + all runtime state needed to resume after a crash.
        Schema::create('research_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->text('goal');
            $t->string('status')->default('pending')->index();
            $t->json('config');                              // limits, allowed_tools, etc.
            $t->unsignedInteger('iteration')->default(0);
            $t->unsignedInteger('tool_call_count')->default(0);
            $t->unsignedInteger('parse_failures')->default(0);
            $t->longText('final_report')->nullable();
            $t->decimal('confidence', 3, 2)->nullable();
            $t->boolean('partial')->default(false);          // report produced due to a limit, not natural finish
            $t->text('last_error')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('deadline_at')->nullable();        // wall-clock timeout
            $t->timestamps();
        });

        // Structured per-turn record: thought / action / observation / finish.
        Schema::create('research_steps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('research_job_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('iteration');
            $t->string('type');                              // StepType
            $t->longText('thought')->nullable();
            $t->json('action')->nullable();                  // {tool, arguments}
            $t->timestamps();
            $t->index(['research_job_id', 'iteration']);
        });

        // Every tool invocation, with dedup fingerprint + retry bookkeeping.
        Schema::create('tool_executions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('research_job_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('research_step_id')->nullable()->constrained()->nullOnDelete();
            $t->string('tool_name')->index();
            $t->json('arguments');
            $t->char('fingerprint', 64);                     // sha256(tool + normalized args)
            $t->string('status')->default('pending');        // pending|running|success|failed|skipped
            $t->longText('observation')->nullable();         // text fed back to the LLM
            $t->json('result')->nullable();                  // structured payload
            $t->text('error')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->unsignedInteger('duration_ms')->nullable();
            $t->timestamps();
            $t->index(['research_job_id', 'fingerprint']);   // fast duplicate lookup
        });

        // The LLM conversation memory (what we replay to the model each turn).
        Schema::create('research_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('research_job_id')->constrained()->cascadeOnDelete();
            $t->string('role');                              // system|assistant|tool|human
            $t->longText('content');
            $t->json('meta')->nullable();
            $t->unsignedInteger('sequence');
            $t->timestamps();
            $t->index(['research_job_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_messages');
        Schema::dropIfExists('tool_executions');
        Schema::dropIfExists('research_steps');
        Schema::dropIfExists('research_jobs');
    }
};
