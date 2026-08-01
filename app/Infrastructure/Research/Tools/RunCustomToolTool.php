<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
use App\Application\Research\Tools\RetryableToolException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use App\Models\CustomTool;

/** Run a previously saved custom tool (skill) in the sandbox. */
class RunCustomToolTool implements Tool
{
    public function __construct(private SandboxClient $sandbox) {}

    public function name(): string
    {
        return 'run_custom_tool';
    }

    public function description(): string
    {
        $names = CustomTool::query()->pluck('name')->take(20)->implode(', ');

        return 'Run one of your saved custom tools (skills) in the sandbox by name. '
            .($names !== '' ? "Available: {$names}." : 'None saved yet — build one with save_custom_tool first.');
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'The saved tool name.'],
                'args' => ['type' => 'string', 'description' => 'Input substituted for {args} in the command (optional).'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        $tool = CustomTool::where('name', strtolower($args->string('name')))->first();
        if (! $tool) {
            return ToolResult::fail("No custom tool named \"{$args->string('name')}\". Save one first with save_custom_tool.");
        }

        $input = $args->string('args');
        $command = str_contains($tool->command, '{args}')
            ? str_replace('{args}', $input, $tool->command)
            : trim($tool->command.' '.$input);

        try {
            $r = $this->sandbox->exec($ctx->workspaceId(), $command);
            $tool->increment('run_count');
        } catch (SandboxException $e) {
            throw new RetryableToolException($e->getMessage());
        }

        $out = trim((string) ($r['stdout'] ?? ''));
        $err = trim((string) ($r['stderr'] ?? ''));

        return ToolResult::ok(
            "[{$tool->name}] exit {$r['exit_code']}\n".($out !== '' ? $out : $err),
            ['name' => $tool->name, 'exit_code' => $r['exit_code'] ?? -1, 'stdout' => $out, 'stderr' => $err],
        );
    }
}
