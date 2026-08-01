<?php

namespace App\Providers;

use App\Application\Settings\SettingsService;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        // Merge UI-configured settings into config for this process. The worker
        // additionally re-applies per iteration (see AdvanceResearchJob) so live
        // edits take effect without a restart.
        $this->app->make(SettingsService::class)->apply();
    }
}
