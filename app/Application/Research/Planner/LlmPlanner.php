<?php

namespace App\Application\Research\Planner;

use App\Application\Research\Tools\ToolRegistry;
use App\Domain\Research\Contracts\LlmClient;
use App\Domain\Research\Contracts\Planner;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
use App\Domain\Research\ValueObjects\Decision;
use App\Domain\Research\ValueObjects\FinishDecision;
use App\Domain\Research\ValueObjects\ResearchContext;
use Illuminate\Support\Facades\Cache;

/**
 * The "brain" adapter. Turns context into a prompt, calls the LLM, and parses
 * the reply into a Decision. It records the prompt + raw output to the trace so
 * a supervisor can see EXACTLY what the model was told and what it said.
 */
class LlmPlanner implements Planner
{
    public function __construct(
        private LlmClient $llm,
        private DecisionParser $parser,
        private PromptBuilder $prompts,
        private ToolRegistry $registry,
        private TraceRecorder $trace,
    ) {}

    public function decide(ResearchContext $ctx): Decision
    {
        $system = $this->prompts->system($ctx);
        $messages = array_merge($ctx->messages, [
            ['role' => 'user', 'content' => $this->prompts->stateUser($ctx)],
        ]);

        $started = (int) (microtime(true) * 1000);
        try {
            $raw = $this->llm->complete($system, $messages, $this->thinkingSink($ctx));
        } finally {
            Cache::forget($this->thinkingKey($ctx));   // clear the live preview
        }
        $elapsed = (int) (microtime(true) * 1000) - $started;

        // Persist exactly what went in and came out — invaluable when debugging
        // why the agent made a particular choice.
        $this->trace->record($ctx->job, EventType::Thought, 'LLM produced a decision', $this->llmPayload($system, $messages, $raw), $elapsed);

        return $this->parser->parse($raw, $this->registry);
    }

    public function summarizeBestEffort(ResearchContext $ctx, string $reason): string
    {
        $system = $this->prompts->system($ctx);
        $messages = array_merge($ctx->messages, [
            ['role' => 'user', 'content' => $this->prompts->bestEffort($reason)],
        ]);

        $raw = $this->llm->complete($system, $messages);

        try {
            $decision = $this->parser->parse($raw, $this->registry);
            if ($decision instanceof FinishDecision) {
                return $decision->report;
            }
        } catch (InvalidDecisionException) {
            // Fall through — return the raw text as a last resort.
        }

        return "Best-effort report ({$reason}):\n\n".trim($raw);
    }

    private function thinkingKey(ResearchContext $ctx): string
    {
        return "research:llm:think:{$ctx->job->id}";
    }

    /**
     * A progress sink that publishes the model's reasoning as it streams, so the
     * live activity banner can always show "what it's thinking right now". It
     * keeps the worker heartbeat fresh (so a long think isn't mistaken for a dead
     * queue) and publishes two views the UI falls back through:
     *
     *   - `text`: the clean, structured `thought` field parsed out of the JSON
     *     answer as it's generated (emitted first per our response contract).
     *   - `raw`:  a best-effort fallback so there is ALWAYS something to show —
     *     the reasoning model's chain-of-thought if it streams one, otherwise the
     *     partial answer with the JSON scaffolding trimmed to read as prose.
     */
    private function thinkingSink(ResearchContext $ctx): callable
    {
        $key = $this->thinkingKey($ctx);

        return function (array $partial) use ($key) {
            $content = (string) ($partial['content'] ?? '');
            $thinking = trim((string) ($partial['thinking'] ?? ''));

            $thought = $this->partialThought($content);
            $raw = $thinking !== '' ? $thinking : $this->readableTail($content);

            Cache::put($key, ['text' => $thought, 'raw' => $raw, 'at' => time()], 300);
            Cache::put('research:worker:last_seen', time(), 300);
        };
    }

    /**
     * A last-resort readable view of the partial answer when neither a parsed
     * `thought` nor a reasoning channel is available yet: strip the leading JSON
     * scaffolding (`{`, quotes, the `"thought":` key) so what's left reads as the
     * prose the model is currently producing rather than raw JSON noise.
     */
    private function readableTail(string $content): string
    {
        $s = ltrim($content);
        if (($s[0] ?? '') === '{') {
            $s = ltrim(substr($s, 1));
        }

        // If the thought field has begun, partialThought() already covers it.
        if (str_contains($s, '"thought"')) {
            return '';
        }

        return trim($s, " \t\n\r\"");
    }

    /**
     * Pull the (possibly still-growing) value of the leading `"thought": "…"`
     * field out of a partial JSON string, unescaping as we go. Returns '' until
     * the field starts — so nothing is shown when the model isn't producing text.
     */
    private function partialThought(string $partial): string
    {
        $pos = strpos($partial, '"thought"');
        if ($pos === false) {
            return '';
        }

        $s = ltrim(substr($partial, $pos + 9));       // past the key
        if (($s[0] ?? '') !== ':') {
            return '';
        }
        $s = ltrim(substr($s, 1));
        if (($s[0] ?? '') !== '"') {
            return '';
        }
        $s = substr($s, 1);                            // past the opening quote

        $out = '';
        $escaped = false;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($escaped) {
                $out .= match ($c) {
                    'n' => "\n", 't' => "\t", 'r' => "\r", default => $c
                };
                $escaped = false;

                continue;
            }
            if ($c === '\\') {
                $escaped = true;

                continue;
            }
            if ($c === '"') {
                break;                                 // closing quote — thought complete
            }
            $out .= $c;
        }

        return trim($out);
    }

    private function llmPayload(string $system, array $messages, string $raw): array
    {
        $payload = ['model' => config('research.llm.model')];

        if (config('research.trace.store_prompts')) {
            $payload['system'] = $system;
            $payload['messages'] = $messages;
        }
        if (config('research.trace.store_raw_llm_output')) {
            $payload['raw'] = $raw;
        }

        return $payload;
    }
}
