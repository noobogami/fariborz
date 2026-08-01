<?php

namespace App\Domain\Research\ValueObjects;

/**
 * What the LLM decided to do this turn. Exactly one of the concrete subclasses.
 * The LLM only ever produces a Decision — it never touches a tool itself.
 */
abstract class Decision
{
    public function __construct(
        public readonly string $thought,
    ) {}

    public function thought(): string
    {
        return $this->thought;
    }

    abstract public function toArray(): array;
}
