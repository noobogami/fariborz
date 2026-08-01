<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live "what is it doing right now" state. The orchestrator updates these as it
 * moves through a turn (thinking → running a tool → queued), so the UI can show
 * the current phase and how long it has been in it — and, combined with a worker
 * heartbeat, tell "actively working" apart from "queue is down".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_jobs', function (Blueprint $t) {
            $t->string('current_activity')->nullable()->after('status');
            $t->timestamp('activity_updated_at')->nullable()->after('current_activity');
        });
    }

    public function down(): void
    {
        Schema::table('research_jobs', function (Blueprint $t) {
            $t->dropColumn(['current_activity', 'activity_updated_at']);
        });
    }
};
