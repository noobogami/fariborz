<?php

namespace App\Application\Research\Planner;

use App\Application\Research\Tools\ToolRegistry;
use App\Domain\Research\ValueObjects\Decision;
use App\Domain\Research\ValueObjects\FinishDecision;
use App\Domain\Research\ValueObjects\ToolCall;
use JsonSchema\Validator;

/**
 * Enforces the LLM contract. Tolerant of the common ways models wrap JSON
 * (code fences, leading prose) but strict about the resulting shape. On any
 * violation it throws InvalidDecisionException, which the orchestrator turns
 * into a corrective observation rather than a crash.
 */
class DecisionParser
{
    public function parse(string $raw, ToolRegistry $registry): Decision
    {
        $json = $this->extractJson($raw);

        if ($json === null || ! isset($json['action'])) {
            throw new InvalidDecisionException('Response was not a JSON object with an "action" field.');
        }

        return match ($json['action']) {
            'finish' => $this->parseFinish($json),
            'tool' => $this->parseTool($json, $registry),
            default => throw new InvalidDecisionException("Unknown action \"{$json['action']}\". Must be \"tool\" or \"finish\"."),
        };
    }

    private function parseFinish(array $json): FinishDecision
    {
        if (! isset($json['report']) || ! is_string($json['report']) || trim($json['report']) === '') {
            throw new InvalidDecisionException('A "finish" action requires a non-empty "report" string.');
        }

        return new FinishDecision(
            report: $json['report'],
            confidence: isset($json['confidence']) ? (float) $json['confidence'] : null,
            thought: (string) ($json['thought'] ?? ''),
        );
    }

    private function parseTool(array $json, ToolRegistry $registry): ToolCall
    {
        $name = $json['tool'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new InvalidDecisionException('A "tool" action requires a "tool" name.');
        }

        if (! $registry->has($name)) {
            $available = implode(', ', $registry->names());
            throw new InvalidDecisionException("Unknown tool \"{$name}\". Available tools: {$available}.");
        }

        $arguments = $json['arguments'] ?? [];
        if (! is_array($arguments)) {
            throw new InvalidDecisionException('"arguments" must be an object.');
        }

        $this->validateAgainstSchema($arguments, $registry->get($name)->schema(), $name);

        return new ToolCall(
            tool: $name,
            arguments: $arguments,
            thought: (string) ($json['thought'] ?? ''),
        );
    }

    /** Validate the LLM's arguments against the tool's JSON schema. */
    private function validateAgainstSchema(array $arguments, array $schema, string $tool): void
    {
        // Encode/decode to objects because justinrainbow/json-schema wants stdClass.
        $data = json_decode(json_encode($arguments ?: new \stdClass));
        $schemaObj = json_decode(json_encode($schema));

        $validator = new Validator;
        $validator->validate($data, $schemaObj);

        if (! $validator->isValid()) {
            $errors = collect($validator->getErrors())
                ->map(fn ($e) => trim(($e['property'] ? $e['property'].': ' : '').$e['message']))
                ->implode('; ');

            throw new InvalidDecisionException("Arguments for \"{$tool}\" are invalid: {$errors}.");
        }
    }

    /** Pull the first JSON object out of a possibly-noisy completion. */
    private function extractJson(string $raw): ?array
    {
        $raw = trim($raw);

        // Strip reasoning-model <think>…</think> blocks (e.g. Qwen3) if any leak through.
        $raw = trim(preg_replace('/<think>.*?<\/think>/is', '', $raw) ?? $raw);

        // Strip ```json ... ``` fences if present.
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $m)) {
            $raw = $m[1];
        }

        // Fast path: the whole thing is JSON.
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fallback: grab the first balanced {...} block.
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
