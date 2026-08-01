<?php

namespace App\Domain\Research\ValueObjects;

/**
 * The outcome of executing a tool. `observation` is the text handed back to
 * the LLM; `data` is the structured payload kept for the record/trace.
 */
final class ToolResult
{
    private function __construct(
        public readonly bool $success,
        public readonly string $observation,
        public readonly array $data = [],
        public readonly ?string $error = null,
        public readonly bool $deferred = false, // e.g. ask_human queued, no answer yet
        public readonly bool $pauseLoop = false, // park the job: a worker sub-agent must finish first
    ) {}

    public static function ok(string $observation, array $data = []): self
    {
        return new self(true, $observation, $data);
    }

    public static function fail(string $error): self
    {
        return new self(false, "The tool failed: {$error}", error: $error);
    }

    /** Succeeded in the sense of "handled", but no answer yet (async human). */
    public static function deferred(string $observation, array $data = []): self
    {
        return new self(true, $observation, $data, deferred: true);
    }

    /**
     * Handled, but the loop must PAUSE — a worker sub-agent is now running and
     * will resume this (supervisor) job when it reports back. The orchestrator
     * records the observation but does NOT reschedule.
     */
    public static function pause(string $observation, array $data = []): self
    {
        return new self(true, $observation, $data, pauseLoop: true);
    }
}
