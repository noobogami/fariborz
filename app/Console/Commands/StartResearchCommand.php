<?php

namespace App\Console\Commands;

use App\Application\Research\StartResearch;
use Illuminate\Console\Command;

/**
 *   php artisan research:start "Determine whether Company X is a good supplier"
 *   php artisan research:start "..." --max-iterations=15 --tool=google_search --tool=ask_human
 */
class StartResearchCommand extends Command
{
    protected $signature = 'research:start
        {goal : The research goal}
        {--max-iterations= : Override the max iteration limit for this job}
        {--tool=* : Restrict the job to these tools (repeatable). Omit for all.}';

    protected $description = 'Start a new autonomous research job.';

    public function handle(StartResearch $start): int
    {
        $overrides = [];

        if ($this->option('max-iterations')) {
            $overrides['limits']['max_iterations'] = (int) $this->option('max-iterations');
        }
        if ($tools = $this->option('tool')) {
            $overrides['allowed_tools'] = $tools;
        }

        $job = $start->handle($this->argument('goal'), $overrides);

        $this->info("Research job started: {$job->id}");
        $this->line('Follow the trace with:');
        $this->line("  <fg=cyan>php artisan research:trace {$job->id}</>");
        $this->line('Or tail the live log:');
        $this->line('  <fg=cyan>tail -f storage/logs/research-'.now()->format('Y-m-d').'.log</>');

        return self::SUCCESS;
    }
}
