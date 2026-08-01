<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task dependencies. A task lists the seq numbers of the tasks that must be
 * VERIFIED (Done) before it can start. Independent tasks (e.g. a web server that
 * doesn't need the chapters) declare no deps and run in parallel; dependent
 * tasks (chapter N needs chapter N-1) wait. Default is "the previous task".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_tasks', function (Blueprint $t) {
            $t->json('depends_on')->nullable()->after('brief');
        });
    }

    public function down(): void
    {
        Schema::table('research_tasks', function (Blueprint $t) {
            $t->dropColumn('depends_on');
        });
    }
};
