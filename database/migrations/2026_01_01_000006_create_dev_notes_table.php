<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ── DEV NOTES MODULE ─────────────────────────────────────────────────────────
 * Scratchpad for ideas / fixes jotted down during development. Fully optional
 * side module — to remove it, delete this migration (and drop the table), the
 * DevNote model, the DevNotes controller, the devnotes view, and the marked
 * blocks in routes/web.php and resources/views/dashboard/layout.blade.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dev_notes', function (Blueprint $t) {
            $t->id();
            $t->text('body');
            $t->boolean('done')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_notes');
    }
};
