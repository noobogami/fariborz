<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The structured verdict a REVIEWER agent records on ITS OWN job row before
 * ending its run — {verdict: accept|revise, notes: string, confidence: float}.
 * The reviewer's deliverable is this judgment, not an artifact or free-text
 * report; ResumeSupervisorOnChildDone reads it to apply accept/revise to the
 * task it was reviewing, and falls back to the supervisor's own review_task
 * when it is null (the reviewer never called submit_review — ran out of turns,
 * crashed, or the model finished directly instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_jobs', function (Blueprint $t) {
            $t->json('review_verdict')->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('research_jobs', function (Blueprint $t) {
            $t->dropColumn('review_verdict');
        });
    }
};
