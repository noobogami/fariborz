<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;

/** List the files in this job's sandbox workspace. */
class ListFilesTool implements Tool
{
    public function __construct(private SandboxClient $sandbox) {}

    public function name(): string
    {
        return 'list_files';
    }

    public function description(): string
    {
        return 'List the files/folders in the sandbox workspace (to see your project '
            .'structure). node_modules/.git/vendor are skipped.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Subdirectory (default the workspace root).'],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        try {
            $r = $this->sandbox->list($ctx->workspaceId(), $args->string('path', '.'));
        } catch (SandboxException $e) {
            return ToolResult::fail($e->getMessage());
        }

        $entries = $r['entries'] ?? [];
        if (empty($entries)) {
            return ToolResult::ok('The workspace is empty.', ['entries' => []]);
        }

        return ToolResult::ok("Workspace files:\n".implode("\n", $entries), ['entries' => $entries]);
    }
}
