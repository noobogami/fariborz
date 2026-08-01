<?php

namespace App\Infrastructure\Research\Tools;

use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use App\Models\CustomTool;

/**
 * The agent saves a reusable "skill" it built (a sandbox command) so it can
 * re-run it later with run_custom_tool — and so a human can review it and
 * promote the useful ones into the core codebase.
 */
class SaveCustomToolTool implements Tool
{
    public function name(): string
    {
        return 'save_custom_tool';
    }

    public function description(): string
    {
        return 'Save a reusable tool you built in the sandbox (a shell command) under a '
            .'name, so you can re-run it later with run_custom_tool. Use "{args}" in the '
            .'command where caller input should go. Saved tools are shown to the human to '
            .'review and possibly build into the core.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Unique machine name, e.g. "count_words".'],
                'description' => ['type' => 'string', 'description' => 'What it does and when to use it.'],
                'command' => ['type' => 'string', 'description' => 'Shell command run in the sandbox. Use {args} for input.'],
            ],
            'required' => ['name', 'description', 'command'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        $name = preg_replace('/[^a-z0-9_]/', '_', strtolower($args->string('name')));

        CustomTool::updateOrCreate(
            ['name' => $name],
            [
                'description' => $args->string('description'),
                'command' => $args->string('command'),
                'created_by_job' => $ctx->jobId(),
            ],
        );

        return ToolResult::ok(
            "Saved custom tool \"{$name}\". Call it later with run_custom_tool. "
            .'It is now listed for the human to review.',
            ['name' => $name],
        );
    }
}
