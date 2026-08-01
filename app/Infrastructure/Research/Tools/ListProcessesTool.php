<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;

/**
 * See what is actually running in the sandbox — the way a human debugs with
 * `ps`. Longest-running / heaviest processes surface first, so a command that
 * hung or is downloading forever is obvious. Pair with kill_process.
 */
class ListProcessesTool implements Tool
{
    public function __construct(private SandboxClient $sandbox) {}

    public function name(): string
    {
        return 'list_processes';
    }

    public function description(): string
    {
        return 'List processes running in the sandbox (longest-running first) with pid, '
            .'elapsed seconds, CPU%%, memory%% and the command. Use it when a command '
            .'hung, timed out, or is slow, to see WHAT is stuck — then kill_process the pid. '
            .'A process with a large elapsed time or high CPU is usually your culprit.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass,
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        try {
            $r = $this->sandbox->ps();
        } catch (SandboxException $e) {
            return ToolResult::fail($e->getMessage());
        }

        $procs = $r['processes'] ?? [];
        if (empty($procs)) {
            return ToolResult::ok('No user processes are running in the sandbox right now.', ['processes' => []]);
        }

        $mine = $ctx->jobId();
        $lines = collect($procs)->take(40)->map(function ($p) use ($mine) {
            $tag = ($p['job'] ?? null) === $mine ? ' ← this job' : (($p['job'] ?? null) ? " (job {$p['job']})" : '');

            return sprintf(
                'pid %-6d  %4ds  cpu %-4s mem %-4s  %s%s',
                $p['pid'] ?? 0, $p['elapsed_s'] ?? 0, $p['cpu'] ?? '0', $p['mem'] ?? '0',
                $p['command'] ?? '', $tag
            );
        })->implode("\n");

        return ToolResult::ok(
            "Running processes (longest-running first):\n{$lines}\n\n"
            .'If one is stuck or running away, kill_process its pid.',
            ['processes' => $procs]
        );
    }
}
