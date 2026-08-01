<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Sandbox\SandboxClient;
use App\Application\Research\Sandbox\SandboxException;
use App\Application\Research\Tools\RetryableToolException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;

/** Create or overwrite a file in this job's sandbox workspace. */
class WriteFileTool implements Tool
{
    public function __construct(private SandboxClient $sandbox) {}

    public function name(): string
    {
        return 'write_file';
    }

    public function description(): string
    {
        return 'Create or overwrite a file in the sandbox workspace (write the full file '
            .'content). Use it to author source code, configs, tests, etc. Then use '
            .'run_command to build/run/test them.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Path relative to the workspace, e.g. "src/index.js".'],
                'content' => ['type' => 'string', 'description' => 'The full file content.'],
            ],
            'required' => ['path', 'content'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        // Weak models sometimes echo their own prompt scaffolding (the "CURRENT
        // STATE" / task-list block) into the file content — that garbage then ends
        // up in the deliverable. Strip it so the file holds only real content, and
        // tell the agent so it stops doing it.
        [$content, $leaked] = $this->stripPromptLeak($args->string('content'));

        try {
            $r = $this->sandbox->write($ctx->workspaceId(), $args->string('path'), $content);
        } catch (SandboxException $e) {
            throw new RetryableToolException($e->getMessage());
        }

        $note = $leaked
            ? ' ⚠️ Your content contained your OWN prompt/state text (a "CURRENT STATE"/task-list block); '
                .'it was stripped so the file holds only the real deliverable. Write ONLY the file content — never your reasoning or state.'
            : '';

        return ToolResult::ok(
            "Wrote {$args->string('path')} ({$r['bytes']} bytes).{$note}",
            ['path' => $args->string('path'), 'bytes' => $r['bytes'] ?? 0, 'stripped_prompt_leak' => $leaked],
        );
    }

    /**
     * Cut the content at the first sign that the model started echoing its own
     * prompt (the injected CURRENT STATE / task list / "respond with JSON" text).
     *
     * @return array{0:string,1:bool} [cleaned content, whether anything was stripped]
     */
    private function stripPromptLeak(string $content): array
    {
        $markers = [
            '/\R\h*CURRENT STATE\h*\R=+/u',
            '/\RIteration:\s*\d+\s+of\s+max\s+\d+/u',
            '/\R\h*Pending human questions/u',
            '/\R\h*Human availability:/u',
            '/\R\h*Decide the single (most valuable )?next action/u',
            '/\R\h*THE GOAL \(never lose sight/u',
            '/\R\h*YOUR TASK LIST/u',
            '/\R\h*Respond with JSON only\.?/u',
        ];

        $cut = strlen($content);
        foreach ($markers as $re) {
            if (preg_match($re, $content, $m, PREG_OFFSET_CAPTURE)) {
                $cut = min($cut, $m[0][1]);
            }
        }

        return $cut < strlen($content)
            ? [rtrim(substr($content, 0, $cut))."\n", true]
            : [$content, false];
    }
}
