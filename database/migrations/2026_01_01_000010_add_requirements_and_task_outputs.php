<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns that make a supervised project UNDERSTAND before it acts:
 *
 *  - research_jobs.requirements — the normalized, authoritative spec a supervisor
 *    extracts from the raw goal ONCE, before planning: the restatement, hard
 *    constraints, explicit ordering, the concrete deliverable and acceptance
 *    checks. Re-anchored into every supervisor turn AND every worker brief so the
 *    goal and the plan stop fighting (the weak model no longer re-derives intent,
 *    differently, each turn).
 *  - research_tasks.outputs — the file path(s) a task WRITES. Declared in the plan
 *    so a dependent worker is told exactly which files its inputs live in (making
 *    the "shared workspace" handoff concrete instead of a vague "read what they
 *    wrote"), and so the reviewer knows what to verify.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_jobs', function (Blueprint $t) {
            $t->json('requirements')->nullable()->after('goal');
        });

        Schema::table('research_tasks', function (Blueprint $t) {
            $t->json('outputs')->nullable()->after('brief');
        });
    }

    public function down(): void
    {
        Schema::table('research_jobs', fn (Blueprint $t) => $t->dropColumn('requirements'));
        Schema::table('research_tasks', fn (Blueprint $t) => $t->dropColumn('outputs'));
    }
};
