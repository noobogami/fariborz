<?php

namespace App\Application\Research\Planner;

use App\Domain\Research\Contracts\LlmClient;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
use App\Models\ResearchJob;

/**
 * The INTAKE step. Before a supervisor plans anything, it spends ONE bounded LLM
 * turn turning the raw, messy goal into a precise, literal spec: what the user
 * actually wants, the hard constraints, the explicit ORDERING they demanded, the
 * concrete deliverable, and checkable acceptance conditions.
 *
 * Why this exists (both from real failed runs):
 *  - "I won't accept less than 100 AGENTS" was misread as "generate 100 SCENARIOS"
 *    — a quantity about orchestration got mapped onto content. The prompt below
 *    forces that distinction.
 *  - "first deploy the UI so I can see each scenario as it completes" was ignored;
 *    the planner put deploy LAST, and every later turn burned time reconciling the
 *    goal ("UI first") against the plan ("UI last"). Ordering is captured verbatim
 *    so the plan can embody it.
 *
 * The result is stored ONCE on the job (research_jobs.requirements) and re-anchored
 * into every supervisor turn and every worker brief. It is deliberately literal:
 * the analyst does NOT add scope, it only extracts what the user said.
 */
class GoalComprehension
{
    public function __construct(
        private LlmClient $llm,
        private TraceRecorder $trace,
    ) {}

    /**
     * Analyze the job's goal and persist the normalized spec on the job. Always
     * stores a non-null structure (a safe fallback if the model's reply can't be
     * parsed) so this never runs twice for the same job / never loops.
     *
     * @return array<string,mixed> the stored requirements
     */
    public function analyze(ResearchJob $job): array
    {
        $started = (int) (microtime(true) * 1000);
        $raw = $this->llm->complete($this->system(), [
            ['role' => 'user', 'content' => "THE USER'S GOAL (verbatim):\n\"{$job->goal}\"\n\nExtract the spec now. Respond with JSON only."],
        ]);
        $elapsed = (int) (microtime(true) * 1000) - $started;

        $req = $this->normalize($this->extractJson($raw), $job->goal);

        $job->update(['requirements' => $req]);

        $this->trace->record($job, EventType::Thought,
            'Understood the goal — '.($req['restatement'] ?: $job->goal),
            ['requirements' => $req, 'raw' => $raw], $elapsed);

        return $req;
    }

    private function system(): string
    {
        return <<<'PROMPT'
        You are the INTAKE ANALYST for an autonomous build/research system that is driven
        by a WEAK local model. Your ONE job: read the user's goal and turn it into a precise,
        LITERAL specification the rest of the system can trust. Extract only what the user
        said — do NOT invent scope, features, or numbers they did not ask for.

        Pay special attention to three things weak planners get wrong:

        1. QUANTITIES & HARD LIMITS — capture exact numbers and what they COUNT. Critically,
           a number about how much WORK/ORCHESTRATION ("spawn at least 100 agents", "use many
           workers", "take your time") is NOT a number about CONTENT ("write 100 scenarios").
           Keep those separate and say which is which. If the user set a floor ("I won't accept
           less than N …"), record it verbatim as a constraint and name what N counts.

        2. ORDERING / PHASING — if the user demanded a sequence ("first deploy the UI so I can
           see each result as it completes", "do X before Y", "start with …"), capture it
           EXACTLY as an ordering item. Incremental/"see it as it goes" intent means a shell/UI
           is deployed FIRST and filled in progressively — record that, don't reorder it away.

        3. THE CONCRETE DELIVERABLE — what must exist at the end (a served web app at a URL? a
           file? a report?), and the acceptance conditions that make it "done".

        Respond with a SINGLE JSON object and nothing else (no prose, no code fences):
        {
          "restatement": "<one or two plain sentences: what the user actually wants>",
          "constraints": ["<hard rule that MUST hold, e.g. 'Spawn at least 100 sub-agents/tasks (this counts AGENTS, not content items)'>", "..."],
          "ordering": ["<explicit step the user demanded, in order, e.g. 'Build and deploy the UI FIRST, before generating content, so each result is visible as it completes'>", "..."],
          "deliverable": "<the concrete final artifact, e.g. 'A web app served on the sandbox with a UI to switch between each result'>",
          "acceptance": ["<checkable condition for done, e.g. 'The UI is deployed and reachable at a published port'>", "..."]
        }

        If the user gave no explicit ordering, use an empty array for "ordering". Never omit a
        key. Be faithful and literal.
        PROMPT;
    }

    /**
     * Coerce whatever the model returned into the fixed shape, with a goal-derived
     * fallback so a parse failure still yields a usable, non-null spec.
     *
     * @param  array<string,mixed>|null  $json
     * @return array<string,mixed>
     */
    private function normalize(?array $json, string $goal): array
    {
        $json ??= [];

        $strList = function ($v): array {
            $out = [];
            foreach ((array) $v as $item) {
                $s = trim((string) (is_scalar($item) ? $item : json_encode($item)));
                if ($s !== '') {
                    $out[] = $s;
                }
            }

            return array_values($out);
        };

        $restatement = trim((string) ($json['restatement'] ?? ''));

        return [
            'restatement' => $restatement !== '' ? $restatement : mb_strimwidth($goal, 0, 400, '…'),
            'constraints' => $strList($json['constraints'] ?? []),
            'ordering' => $strList($json['ordering'] ?? []),
            'deliverable' => trim((string) ($json['deliverable'] ?? '')),
            'acceptance' => $strList($json['acceptance'] ?? []),
        ];
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
