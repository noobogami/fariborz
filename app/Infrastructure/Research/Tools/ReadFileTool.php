<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;

/** Read a file back from the sandbox workspace. */
class ReadFileTool implements Tool
{
    public function __construct(private SandboxClient $sandbox) {}

    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Read a file from the sandbox workspace (e.g. to review code you wrote or '
            .'inspect generated output).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Path relative to the workspace.'],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        try {
            $r = $this->sandbox->read($ctx->workspaceId(), $args->string('path'));
        } catch (SandboxException $e) {
            return ToolResult::fail($e->getMessage());
        }

        return ToolResult::ok(
            "File {$args->string('path')}:\n".($r['content'] ?? ''),
            ['path' => $args->string('path')],
        );
    }
}
