<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
use App\Application\Research\Tools\RetryableToolException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;

/**
 * Run a shell command inside the isolated sandbox container (never the host),
 * in this job's workspace. Returns the exit code, stdout and stderr so the agent
 * can read build/test failures and iterate. This is how the agent builds and
 * tests software.
 */
class RunCommandTool implements Tool
{
    public function __construct(private SandboxClient $sandbox) {}

    public function name(): string
    {
        return 'run_command';
    }

    public function description(): string
    {
        return 'Run a shell command in the isolated code sandbox (this job\'s workspace). '
            .'Use it to install deps, build, run, and TEST code (e.g. "npm test", "pytest", '
            .'"php artisan test"). Returns exit_code + stdout + stderr — read them to fix '
            .'errors and re-run. Node, Python, PHP, git and build tools are available.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'description' => 'The shell command to run.'],
                'cwd' => ['type' => 'string', 'description' => 'Working dir relative to the workspace (default ".").'],
                'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 300, 'description' => 'Seconds before the command is killed (max 300).'],
            ],
            'required' => ['command'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        try {
            $r = $this->sandbox->exec($ctx->workspaceId(),
                $args->string('command'),
                $args->string('cwd', '.'),
                $args->int('timeout') ?: null,
            );
        } catch (SandboxException $e) {
            throw new RetryableToolException($e->getMessage());
        }

        $exit = $r['exit_code'] ?? -1;
        $out = trim((string) ($r['stdout'] ?? ''));
        $err = trim((string) ($r['stderr'] ?? ''));
        $ms = $r['duration_ms'] ?? 0;

        $observation = "$ {$args->string('command')}\n"
            ."exit code: {$exit} ({$ms}ms)".(($r['timed_out'] ?? false) ? ' [TIMED OUT]' : '')."\n"
            .($out !== '' ? "\n--- stdout ---\n{$out}\n" : '')
            .($err !== '' ? "\n--- stderr ---\n{$err}\n" : '');

        // Non-zero exit is NOT a tool failure — it's a result the agent must read
        // and act on (fix the code, re-run). Return it as a normal observation.
        return ToolResult::ok($observation, [
            'command' => $args->string('command'),
            'exit_code' => $exit,
            'stdout' => $out,
            'stderr' => $err,
            'duration_ms' => $ms,
        ]);
    }
}
