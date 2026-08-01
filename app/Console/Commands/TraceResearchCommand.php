<?php

namespace App\Console\Commands;

use App\Application\Research\Tracing\ResearchTraceReader;
use Illuminate\Console\Command;

/**
 * The supervisor's window into a run. Reconstructs the exact path the agent
 * took — days later, from persisted state alone.
 *
 *   php artisan research:trace <job-id>
 *   php artisan research:trace <job-id> --verbose   (show full payloads)
 *   php artisan research:trace <job-id> --iteration=5
 */
class TraceResearchCommand extends Command
{
    protected $signature = 'research:trace
        {job : The research job UUID}
        {--iteration= : Only show events for this iteration}
        {--type= : Only show events of this type (e.g. thought,tool_succeeded)}
        {--full : Print full payloads (prompts, results, reports)}';

    protected $description = 'Reconstruct and print the full path a research agent took.';

    public function handle(ResearchTraceReader $reader): int
    {
        $data = $reader->build($this->argument('job'), withPayloads: (bool) $this->option('full'));

        $this->printHeader($data['job'], $data['stats']);
        $this->printTimeline($data['timeline']);
        $this->printFooter($data['job']);

        return self::SUCCESS;
    }

    private function printHeader(array $job, array $stats): void
    {
        $this->newLine();
        $this->line('<fg=cyan>══════════════════════════════════════════════════════════════════</>');
        $this->line("  <options=bold>RESEARCH TRACE</>  <fg=gray>{$job['id']}</>");
        $this->line('<fg=cyan>══════════════════════════════════════════════════════════════════</>');
        $this->line("  <options=bold>Goal:</>   {$job['goal']}");
        $this->line("  <options=bold>Status:</> {$this->colorStatus($job['status'])}   "
            ."<options=bold>Iterations:</> {$job['iteration']}   "
            ."<options=bold>Tool calls:</> {$job['tool_calls']}"
            .($job['confidence'] !== null ? "   <options=bold>Confidence:</> {$job['confidence']}" : ''));
        $this->line('  <options=bold>Started:</> '.($job['started_at'] ?? '—')
            .'   <options=bold>Finished:</> '.($job['finished_at'] ?? '—'));

        $tools = collect($stats['tool_usage'])
            ->map(fn ($t) => "{$t['tool']} ×{$t['calls']}".($t['failed'] ? " ({$t['failed']} failed)" : ''))
            ->implode(', ');
        if ($tools) {
            $this->line("  <options=bold>Tools used:</> {$tools}");
        }
        if ($stats['open_questions'] > 0) {
            $this->line("  <fg=yellow>Open human questions: {$stats['open_questions']}</>");
        }
        $this->line('<fg=cyan>──────────────────────────────────────────────────────────────────</>');
    }

    private function printTimeline(array $timeline): void
    {
        $onlyIter = $this->option('iteration');
        $onlyType = $this->option('type') ? explode(',', $this->option('type')) : null;

        $lastIter = null;

        foreach ($timeline as $e) {
            if ($onlyIter !== null && (string) $e['iteration'] !== (string) $onlyIter) {
                continue;
            }
            if ($onlyType !== null && ! in_array($e['type'], $onlyType, true)) {
                continue;
            }

            // Visually group by iteration.
            if ($e['iteration'] !== $lastIter) {
                $this->newLine();
                $this->line("  <fg=magenta;options=bold>┌─ iteration {$e['iteration']}</>");
                $lastIter = $e['iteration'];
            }

            $dur = $e['duration_ms'] !== null ? " <fg=gray>({$e['duration_ms']}ms)</>" : '';
            $seq = str_pad((string) $e['seq'], 3, '0', STR_PAD_LEFT);
            $tag = $this->colorForLevel($e['level'], strtoupper(str_replace('_', ' ', $e['type'])));

            $this->line(sprintf('  <fg=gray>#%s</> │ %s %s: %s%s',
                $seq, $e['glyph'], $tag, $e['summary'], $dur));

            if ($this->option('full') && ! empty($e['payload'])) {
                $json = json_encode($e['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                foreach (explode("\n", $json) as $ln) {
                    $this->line("        <fg=gray>{$ln}</>");
                }
            }
        }
    }

    private function printFooter(array $job): void
    {
        $this->newLine();
        $this->line('<fg=cyan>──────────────────────────────────────────────────────────────────</>');

        if ($job['last_error']) {
            $this->line("  <fg=red>Last error:</> {$job['last_error']}");
        }

        if ($job['report']) {
            $this->line('  <options=bold>FINAL REPORT'.($job['partial'] ? ' (partial — hit a limit)' : '').':</>');
            $this->newLine();
            foreach (explode("\n", wordwrap($job['report'], 90)) as $ln) {
                $this->line("    {$ln}");
            }
        } else {
            $this->line('  <fg=gray>No final report yet.</>');
        }
        $this->newLine();
    }

    private function colorStatus(string $s): string
    {
        return match ($s) {
            'completed' => "<fg=green>{$s}</>",
            'failed' => "<fg=red>{$s}</>",
            'cancelled' => "<fg=yellow>{$s}</>",
            'running' => "<fg=blue>{$s}</>",
            default => $s,
        };
    }

    private function colorForLevel(string $level, string $text): string
    {
        return match ($level) {
            'error' => "<fg=red;options=bold>{$text}</>",
            'warning' => "<fg=yellow>{$text}</>",
            default => "<fg=white>{$text}</>",
        };
    }
}
