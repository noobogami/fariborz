<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;

/** Recent command history (log) from the sandbox container for this job. */
class ContainerLogsTool implements Tool
{
    public function __construct(private SandboxClient $sandbox) {}

    public function name(): string
    {
        return 'container_logs';
    }

    public function description(): string
    {
        return 'Show the recent command history (log) from the sandbox container for this '
            .'job — each command, its exit code and duration. Useful to review what has '
            .'been tried.';
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
            $r = $this->sandbox->logs($ctx->workspaceId());
        } catch (SandboxException $e) {
            return ToolResult::fail($e->getMessage());
        }

        $logs = $r['logs'] ?? [];
        if (empty($logs)) {
            return ToolResult::ok('No commands have run yet in this workspace.', ['logs' => []]);
        }

        $lines = collect($logs)->map(fn ($l) => sprintf(
            '[%s] exit %s (%dms): %s',
            $l['ts'] ?? '', $l['exit_code'] ?? '?', $l['duration_ms'] ?? 0, $l['cmd'] ?? ''
        ))->implode("\n");

        return ToolResult::ok("Sandbox command log:\n{$lines}", ['logs' => $logs]);
    }
}
