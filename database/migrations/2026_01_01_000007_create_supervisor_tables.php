<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supervisor/worker hierarchy. A top-level job runs as a SUPERVISOR that
 * decomposes its goal into a task list and delegates each task to a WORKER
 * sub-job (its own short, goal-focused loop). This keeps long runs aligned to
 * the goal instead of drifting down one rabbit hole.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_jobs', function (Blueprint $t) {
            $t->string('role', 20)->default('solo')->after('goal');   // solo | supervisor | worker
            $t->uuid('parent_job_id')->nullable()->after('role')->index();
        });

        Schema::create('research_tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('research_job_id');                 // the SUPERVISOR job that owns the plan
            $t->unsignedInteger('seq');                  // 1-based position the LLM references
            $t->string('title');
            $t->text('brief');                           // what to do + what "done" looks like
            $t->string('status', 20)->default('pending'); // pending|in_progress|awaiting_review|done|failed
            $t->uuid('child_job_id')->nullable();        // the worker job that ran it
            $t->longText('result')->nullable();          // the worker's final report
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamps();

            $t->foreign('research_job_id')->references('id')->on('research_jobs')->cascadeOnDelete();
            $t->index(['research_job_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_tasks');
        Schema::table('research_jobs', function (Blueprint $t) {
            $t->dropColumn(['role', 'parent_job_id']);
        });
    }
};
