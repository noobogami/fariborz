<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Humans the agent can escalate to. Multiple humans with different
        // expertise/permissions are supported from day one.
        Schema::create('humans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('status')->default('offline');   // HumanStatus
            $t->json('expertise')->nullable();           // ["erp","legal","finance"]
            $t->json('permissions')->nullable();         // what they may answer
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });

        // The async queue for the ask_human tool. Questions live here whether
        // or not a human is around; the agent never blocks on them.
        Schema::create('human_questions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('research_job_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('tool_execution_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('assigned_human_id')->nullable()->constrained('humans')->nullOnDelete();
            $t->text('question');
            $t->json('tags')->nullable();                // routing / expertise hints
            $t->string('status')->default('queued')->index(); // QuestionStatus
            $t->unsignedTinyInteger('priority')->default(5);
            $t->text('answer')->nullable();
            $t->string('resolved_source')->nullable();   // human | google_search | sql_query ...
            $t->decimal('resolved_confidence', 3, 2)->nullable();
            $t->timestamp('asked_at')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->timestamps();
            $t->index(['research_job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('human_questions');
        Schema::dropIfExists('humans');
    }
};
