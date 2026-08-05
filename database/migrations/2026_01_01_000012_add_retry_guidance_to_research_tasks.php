<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A corrective guideline for the NEXT worker attempt at a task. When a worker
 * fails and FailureDiagnosis judges the failure was the worker's approach (not
 * an unavailable model), it writes a concrete instruction here; buildWorkerGoal
 * prepends it to the fresh worker's goal so the retry does not repeat the same
 * mistake. Cleared on a clean (non-guided) retry. Nullable — a first attempt and
 * an availability-only failure carry none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_tasks', function (Blueprint $t) {
            $t->text('retry_guidance')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('research_tasks', function (Blueprint $t) {
            $t->dropColumn('retry_guidance');
        });
    }
};
