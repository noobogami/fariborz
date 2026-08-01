<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime settings edited from the dashboard. Each row overrides one Laravel
 * config path (e.g. "services.tavily.key"). Only DB/Redis/APP_KEY stay in .env;
 * everything else (LLM, API keys, endpoints, limits) is configured here after
 * the app is running. Secret values are stored encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->string('config_key')->primary(); // e.g. research.llm.model
            $t->longText('value')->nullable();
            $t->boolean('secret')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
