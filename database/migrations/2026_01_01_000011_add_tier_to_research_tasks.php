<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capability tier per task. When the supervisor plans, it tags each task with a
 * tier (light / standard / hard) — a bounded "how hard is this?" decision. The
 * worker spawned for the task runs on that tier's model (config research.llm.
 * tiers), giving per-task model routing without hardcoding a model per role.
 * Nullable: an untagged task uses research.llm.default_tier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_tasks', function (Blueprint $t) {
            $t->string('tier')->nullable()->after('outputs');
        });
    }

    public function down(): void
    {
        Schema::table('research_tasks', function (Blueprint $t) {
            $t->dropColumn('tier');
        });
    }
};
