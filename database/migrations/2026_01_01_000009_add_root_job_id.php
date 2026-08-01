<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ROOT of a project tree. Every job in a supervised project (the supervisor,
 * its workers, any sub-supervisors) shares ONE sandbox workspace keyed by this
 * id — so files a worker writes are visible to the others and a served app can
 * be assembled and deployed in one place, instead of scattering across per-job
 * workspaces. For a solo job, root_job_id == id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_jobs', function (Blueprint $t) {
            $t->uuid('root_job_id')->nullable()->after('parent_job_id')->index();
        });

        // Backfill existing rows: solo/supervisor roots point at themselves.
        \Illuminate\Support\Facades\DB::statement('UPDATE research_jobs SET root_job_id = id WHERE root_job_id IS NULL AND parent_job_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('research_jobs', function (Blueprint $t) {
            $t->dropColumn('root_job_id');
        });
    }
};
