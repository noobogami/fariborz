<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;

/**
 * Diagnose the sandbox: the agent asks the environment what it can/can't do
 * (permissions, the host-published port, installed toolchains, what's already
 * listening) and adapts — instead of guessing or being told.
 */
class SandboxInfoTool implements Tool
{
    public function __construct(private SandboxClient $sandbox) {}

    public function name(): string
    {
        return 'sandbox_info';
    }

    public function description(): string
    {
        return 'Diagnose the sandbox environment before you build in it: your permissions, '
            .'the workspace path, which port is published to the host (so a web app is '
            .'viewable), installed toolchains, and what is already listening/running. Call '
            .'this whenever a command fails or hangs, or before starting a server, and adapt.';
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
            $i = $this->sandbox->info($ctx->workspaceId());
        } catch (SandboxException $e) {
            return ToolResult::fail($e->getMessage());
        }

        $free = $i['free_app_ports'] ?? [];
        $pick = $free[0] ?? null;
        $obs = "SANDBOX ENVIRONMENT\n"
            ."workspace: {$i['workspace_root']}\n"
            .($i['write_jail'] ?? '')."\n"
            .'root access: '.(($i['is_root'] ?? false) ? 'yes (you can apt-get install)' : 'no')."\n"
            .'host-published port pool: '.($i['published_app_ports'] ?? 'none')."\n"
            .'free ports right now: '.(empty($free) ? 'none' : implode(', ', $free))."\n"
            .($pick
                ? "→ To expose a web app: bind it to 0.0.0.0:{$pick} (a free published port), then the user opens http://localhost:{$pick}. curl http://localhost:{$pick} to confirm.\n"
                : '')
            ."\n--- diagnostics ---\n".($i['diagnostics'] ?? '');

        return ToolResult::ok($obs, $i);
    }
}
