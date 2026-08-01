<?php

namespace App\Domain\Research\Contracts;

use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;

/**
 * Strategy pattern. Every capability the agent has is a Tool. Adding a new one
 * is: implement this interface + register it in the tag list. Nothing else.
 *
 * Laravel executes tools. The LLM only ever NAMES a tool + arguments.
 */
interface Tool
{
    /** Unique machine name the LLM references, e.g. "google_search". */
    public function name(): string;

    /** Plain-English description injected into the prompt so the LLM knows when to use it. */
    public function description(): string;

    /** JSON Schema for the arguments; used both to prompt and to validate the LLM's output. */
    public function schema(): array;

    public function execute(ToolArguments $args, ResearchContext $context): ToolResult;
}
