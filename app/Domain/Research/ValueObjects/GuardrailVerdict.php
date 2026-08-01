<?php

namespace App\Domain\Research\ValueObjects;

/**
 * The result of a guardrail check.
 *
 *  - `pass()`  → nothing to do.
 *  - `block()` → intercept THIS action; feed `message` back to the LLM as an
 *                 observation so it course-corrects (job keeps running).
 *  - `stop()`  → end the whole job for `reason` (limit/timeout/cancel).
 */
final class GuardrailVerdict
{
    private function __construct(
        public readonly bool $passed,
        public readonly bool $terminal,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
    ) {}

    public static function pass(): self
    {
        return new self(passed: true, terminal: false);
    }

    public static function block(string $message): self
    {
        return new self(passed: false, terminal: false, message: $message);
    }

    public static function stop(string $reason, string $message): self
    {
        return new self(passed: false, terminal: true, reason: $reason, message: $message);
    }

    public function isBlock(): bool
    {
        return ! $this->passed && ! $this->terminal;
    }

    public function isStop(): bool
    {
        return $this->terminal;
    }
}
