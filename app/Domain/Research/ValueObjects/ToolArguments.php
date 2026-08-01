<?php

namespace App\Domain\Research\ValueObjects;

/**
 * A tiny typed wrapper over the raw argument array the LLM produced, so tools
 * read cleanly ($args->string('query')) instead of poking at arrays.
 */
final class ToolArguments
{
    public function __construct(private readonly array $args) {}

    public function string(string $key, string $default = ''): string
    {
        return isset($this->args[$key]) ? (string) $this->args[$key] : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return isset($this->args[$key]) ? (int) $this->args[$key] : $default;
    }

    public function array(string $key, array $default = []): array
    {
        return isset($this->args[$key]) && is_array($this->args[$key]) ? $this->args[$key] : $default;
    }

    public function all(): array
    {
        return $this->args;
    }
}
