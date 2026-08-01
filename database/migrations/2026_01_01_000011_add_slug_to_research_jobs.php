<?php

use App\Models\ResearchJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Human-readable identifiers so a job (and especially its sandbox workspace) is
 * findable by a person, not just a UUID.
 *
 *  - research_jobs.slug — a short readable id per job (e.g. "todo-app-4f21a"),
 *    derived from the goal + a random suffix. Shown in the UI instead of the raw
 *    UUID and accepted anywhere a job id is (URLs, lookups).
 *  - research_jobs.workspace_slug — the ROOT job's slug, denormalized onto every
 *    job in the tree (mirrors how root_job_id is carried). This becomes the
 *    sandbox workspace directory name (/workspace/<workspace_slug>), so the folder
 *    a job builds in is legible instead of an opaque UUID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_jobs', function (Blueprint $t) {
            $t->string('slug')->nullable()->after('id');
            $t->string('workspace_slug')->nullable()->after('root_job_id');
        });

        // Backfill: give every existing job a slug first, then point each row's
        // workspace_slug at the slug of its root (so a whole tree shares one).
        $jobs = DB::table('research_jobs')->select('id', 'goal', 'root_job_id')->get();

        $slugs = [];
        foreach ($jobs as $j) {
            $slug = ResearchJob::generateSlug($j->goal ?? '');
            $slugs[$j->id] = $slug;
            DB::table('research_jobs')->where('id', $j->id)->update(['slug' => $slug]);
        }
        foreach ($jobs as $j) {
            $rootId = $j->root_job_id ?: $j->id;
            DB::table('research_jobs')->where('id', $j->id)
                ->update(['workspace_slug' => $slugs[$rootId] ?? $slugs[$j->id]]);
        }

        Schema::table('research_jobs', function (Blueprint $t) {
            $t->unique('slug');
            $t->index('workspace_slug');
        });
    }

    public function down(): void
    {
        Schema::table('research_jobs', function (Blueprint $t) {
            $t->dropUnique(['slug']);
            $t->dropIndex(['workspace_slug']);
            $t->dropColumn(['slug', 'workspace_slug']);
        });
    }
};
