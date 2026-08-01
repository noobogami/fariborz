<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;

/**
 * Terminate a stuck or runaway process in the sandbox by pid (from
 * list_processes). This is how the agent unblocks itself instead of retrying a
 * command that is hung. The sandbox refuses to kill its own service.
 */
class KillProcessTool implements Tool
{
    public function __construct(private SandboxClient $sandbox) {}

    public function name(): string
    {
        return 'kill_process';
    }

    public function description(): string
    {
        return 'Terminate a stuck or runaway sandbox process by its pid (get pids from '
            .'list_processes). Use it to unblock yourself — e.g. a hung install, a '
            .'download that never ends, or an old server on a port you need — instead of '
            .'retrying a command that keeps timing out.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pid' => ['type' => 'integer', 'minimum' => 2, 'description' => 'The process id to terminate (from list_processes).'],
            ],
            'required' => ['pid'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        $pid = $args->int('pid');
        if ($pid < 2) {
            return ToolResult::fail('Provide a valid pid (from list_processes).');
        }

        try {
            $this->sandbox->kill($pid);
        } catch (SandboxException $e) {
            return ToolResult::fail($e->getMessage());
        }

        return ToolResult::ok("Killed pid {$pid}. Re-run list_processes to confirm it is gone.", ['pid' => $pid]);
    }
}
