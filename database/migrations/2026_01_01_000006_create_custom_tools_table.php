<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Skills" the agent writes for itself: a reusable command it built in the
 * sandbox, saved so it can re-run it later — and surfaced in the UI so a human
 * can review it and promote the useful ones into the core codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_tools', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name')->unique();          // machine name used by run_custom_tool
            $t->text('description');
            $t->longText('command');               // shell command run in the sandbox ({args} placeholder)
            $t->foreignUuid('created_by_job')->nullable();
            $t->unsignedInteger('run_count')->default(0);
            $t->boolean('promoted')->default(false); // a human marked it for the core
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_tools');
    }
};
