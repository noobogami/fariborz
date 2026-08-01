<?php

namespace App\Domain\Research\ValueObjects;

final class ToolCall extends Decision
{
    public function __construct(
        public readonly string $tool,
        public readonly array $arguments,
        string $thought = '',
    ) {
        parent::__construct($thought);
    }

    public function toolArguments(): ToolArguments
    {
        return new ToolArguments($this->arguments);
    }

    public function toArray(): array
    {
        return [
            'action' => 'tool',
            'tool' => $this->tool,
            'arguments' => $this->arguments,
            'thought' => $this->thought,
        ];
    }
}
