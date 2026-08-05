<?php

namespace App\Application\Research\Planner;

use App\Domain\Research\Contracts\LlmClient;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
use App\Models\ResearchJob;
use App\Models\ResearchTask;

/**
 * The bounded JUDGMENT step for a FAILED worker. When a task's worker crashed or
 * produced a bad result — and it was NOT simply an unavailable model (that case
 * is handled deterministically, upstream) — this spends ONE small LLM turn to
 * decide how the retry should differ:
 *
 *   - retry_with_guidance : the worker's APPROACH/output was wrong; a concrete
 *                           instruction to the next worker will fix it.
 *   - fresh_retry         : nothing specific to instruct (transient / unclear);
 *                           just run a clean new worker.
 *
 * This is deliberately NOT part of the supervisor's main transcript: it sees only
 * a SHORT failure reason plus the task, and returns structured JSON. So it can
 * never balloon the supervisor's context the way routing a failure through a full
 * review turn once did. It NEVER abandons a task — the attempt cap is the only
 * thing that gives up (see ResearchOrchestrator::autoDelegateReadyTasks). A parse
 * failure falls back to fresh_retry, so a bad diagnosis can only ever degrade to
 * today's plain-retry behaviour, never loop or lose the task.
 */
class FailureDiagnosis
{
    public function __construct(
        private LlmClient $llm,
        private TraceRecorder $trace,
    ) {}

    /**
     * Diagnose one failed attempt and return the retry shape.
     *
     * @return array{decision:string, diagnosis:string, guidance:string}
     */
    public function diagnose(ResearchJob $supervisor, ResearchTask $task, string $reason, int $attempt, int $cap): array
    {
        $started = (int) (microtime(true) * 1000);
        $raw = $this->llm->complete($this->system(), [[
            'role' => 'user',
            'content' => "A worker sub-agent just FAILED an attempt at this task.\n\n"
                ."TASK: {$task->title}\n{$task->brief}\n\n"
                ."FAILURE (attempt {$attempt} of {$cap}):\n{$reason}\n\n"
                .'Diagnose it and choose how the next attempt should differ. Respond with JSON only.',
        ]]);
        $elapsed = (int) (microtime(true) * 1000) - $started;

        $out = $this->normalize($this->extractJson($raw));

        $this->trace->record($supervisor, EventType::Thought,
            "Diagnosed task #{$task->seq} failure → {$out['decision']}: ".$out['diagnosis'],
            ['task' => $task->seq, 'diagnosis' => $out, 'reason' => $reason, 'raw' => $raw], $elapsed);

        return $out;
    }

    private function system(): string
    {
        return <<<'PROMPT'
        You diagnose a FAILED attempt by a worker sub-agent in an autonomous build system
        driven by a WEAK local model. The task will be retried automatically; your ONLY job
        is to decide how the NEXT attempt should differ. Do NOT restate the task or write the
        deliverable yourself.

        Choose exactly one decision:
        - "retry_with_guidance": the failure was about the worker's OWN approach or output
          (malformed response, wrong format, missing a required file, misread the task,
          produced a stub). Provide ONE short, concrete instruction that will prevent it.
        - "fresh_retry": the failure was transient or unclear and there is nothing specific
          to instruct — just run a clean new worker.

        Do NOT propose giving up: the system enforces its own attempt limit. Prefer
        "retry_with_guidance" only when you have a genuinely useful, specific instruction;
        otherwise "fresh_retry".

        Respond with a SINGLE JSON object and nothing else (no prose, no code fences):
        {
          "diagnosis": "<one short sentence: what went wrong>",
          "decision": "retry_with_guidance" | "fresh_retry",
          "guidance": "<if retry_with_guidance: one concrete instruction for the next worker; else empty string>"
        }
        PROMPT;
    }

    /**
     * Coerce the model's reply into the fixed shape, defaulting to a plain clean
     * retry so an unparseable/empty diagnosis degrades to today's behaviour.
     *
     * @param  array<string,mixed>|null  $json
     * @return array{decision:string, diagnosis:string, guidance:string}
     */
    private function normalize(?array $json): array
    {
        $json ??= [];

        $decision = $json['decision'] ?? '';
        $guidance = trim((string) ($json['guidance'] ?? ''));
        $diagnosis = trim((string) ($json['diagnosis'] ?? ''));

        // Only honour a guided retry when there is real guidance to give.
        if ($decision === 'retry_with_guidance' && $guidance !== '') {
            return ['decision' => 'retry_with_guidance', 'diagnosis' => $diagnosis, 'guidance' => $guidance];
        }

        return ['decision' => 'fresh_retry', 'diagnosis' => $diagnosis !== '' ? $diagnosis : 'no specific cause identified', 'guidance' => ''];
    }

    /** Pull the first JSON object out of a possibly-noisy completion. */
    private function extractJson(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = trim(preg_replace('/<think>.*?<\/think>/is', '', $raw) ?? $raw);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $m)) {
            $raw = $m[1];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

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
