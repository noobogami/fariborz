<?php

namespace App\Application\Research\Llm;

use App\Application\Research\Planner\DecisionParser;
use App\Application\Research\Planner\InvalidDecisionException;
use App\Application\Research\Tools\ToolRegistry;
use App\Domain\Research\Contracts\LlmClient;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolCall;
use App\Domain\Research\ValueObjects\ToolResult;
use App\Models\ModelBenchmark as ModelBenchmarkRecord;
use Illuminate\Support\Str;
use Throwable;

/**
 * Probes ONE gateway model with a small, cheap-first suite and scores it
 * deterministically — evidence for "which model is worth putting in which
 * tier" instead of vibes. Calls the gateway DIRECTLY through LlmClient with
 * the model pinned into config (the same trick ModelRouter::apply uses) — it
 * deliberately never goes through the planner, guardrails, or a research job,
 * so a benchmark run creates no ResearchJob and never counts as research work.
 *
 * $llm here is (in production) the HealthTrackingLlmClient decorator, so every
 * probe call is a REAL gateway call and — intentionally — feeds GatewayHealth
 * exactly like a normal job turn would. That is correct: the load-control
 * signal should reflect a benchmark hammering the gateway just as much as a
 * research job would.
 *
 * No LLM is ever asked to judge its own probe — every pass/fail check below is
 * plain PHP.
 */
class ModelBenchmark
{
    /** Weighted pass-rate contribution of each probe (renormalised when a probe didn't run). */
    private const WEIGHTS = ['envelope' => 0.5, 'ping' => 0.2, 'reasoning' => 0.2, 'context' => 0.1];

    /**
     * Output tokens every probe budget gets ON TOP of the answer it actually
     * needs — headroom for a REASONING model's chain-of-thought.
     *
     * Hard-won, measured against this gateway: a `think`-enabled qwen3 spends
     * 250-400 tokens reasoning BEFORE it emits the first character of the
     * answer, while the answer itself is ~6 tokens ({"ok":true}). Budget only
     * for the answer and every probe comes back finish_reason=length with
     * content="" — an "empty completion" that looks exactly like a dead model
     * but is really us hanging up mid-thought. That is the same footgun
     * CLAUDE.md documents for the planner, and it made the first real
     * benchmark run report all four local models as failed.
     *
     * Deliberately generous: an unused budget costs NOTHING (the model stops
     * at its own stop token; only truncation costs a probe), so the reserve is
     * sized for a slow reasoner, not the average one.
     */
    private const REASONING_RESERVE = 1024;

    public function __construct(
        private LlmClient $llm,
        private DecisionParser $parser,
        private GatewayHealth $health,
    ) {}

    /**
     * Run the probe suite for $model and return a result ready to persist.
     * `deep` adds the context/needle-in-haystack probe (slow — an extra ~8k
     * token prompt) on top of the default shallow run.
     *
     * @return array{status:string, score:?int, rating:?string, median_ms:?int, suggested_tier:?string, probes:array, low_confidence:bool, error:?string}
     */
    public function run(string $model, bool $deep = false): array
    {
        $lowConfidence = false;
        $probes = [];

        $ping = $this->probePing($model);
        $probes[] = $ping;
        $lowConfidence = $lowConfidence || $this->health->isStrained();

        if (! $ping['ok']) {
            // Cheapest-first, and ping is the reachability floor: if we can't even
            // get a sane reply out of it, nothing downstream is worth spending 1-2
            // minutes probing. This is a FAILED run, not a "broken" RATING — broken
            // means "we talked to it and it can't drive the agent"; failed means we
            // never got that far (down, wrong name, gateway timeout).
            return [
                'status' => 'failed',
                'score' => null,
                'rating' => null,
                'median_ms' => $ping['ms'],
                'suggested_tier' => null,
                'probes' => $probes,
                'low_confidence' => $lowConfidence,
                'error' => $ping['detail'],
            ];
        }

        $probes[] = $this->probeEnvelope($model);
        $lowConfidence = $lowConfidence || $this->health->isStrained();

        $probes[] = $this->probeReasoning($model);
        $lowConfidence = $lowConfidence || $this->health->isStrained();

        if ($deep) {
            $probes[] = $this->probeContext($model);
            $lowConfidence = $lowConfidence || $this->health->isStrained();
        }

        return $this->score($model, $probes, $lowConfidence);
    }

    // ── Probes (cheapest first) ──────────────────────────────────────────────

    /** Reachability + floor latency + does it honour a JSON-object instruction. */
    private function probePing(string $model): array
    {
        return $this->probe(
            'ping', $model, 64,
            'You are a JSON API. Respond with a JSON object only — no prose, no code fences.',
            'Reply with exactly this JSON object: {"ok":true}',
            function (string $raw) {
                $json = $this->extractJsonLoose($raw);
                $ok = is_array($json) && ($json['ok'] ?? null) === true;

                return [$ok, $ok ? '' : 'did not echo {"ok":true}: '.mb_strimwidth($raw, 0, 120, '…')];
            },
        );
    }

    /**
     * The probe that matters most: can this model drive the agent at all. Gives
     * it a miniature tool list and the app's real Decision contract, then feeds
     * the reply through the REAL DecisionParser (not a looser reimplementation)
     * — pass means it parses into a ToolCall for the tool we asked for. This is
     * the exact weak-model failure mode documented in CLAUDE.md: emitting a
     * tool's args with no {"action":"tool",…} wrapper → invalid_llm_response →
     * a dead job.
     */
    private function probeEnvelope(string $model): array
    {
        $registry = new ToolRegistry([$this->probeTool()]);
        $tools = json_encode($registry->definitions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $system = <<<SYS
        You are an autonomous agent. Respond with EXACTLY ONE JSON object and nothing else.

        RESPONSE CONTRACT — valid JSON only, no prose, no code fences:
        To use a tool: {"thought":"<brief reasoning>","action":"tool","tool":"<name>","arguments":{...}}
        "action" is ALWAYS the literal string "tool" — never a tool name. The tool name goes in "tool".

        AVAILABLE TOOLS:
        {$tools}
        SYS;

        return $this->probe(
            'envelope', $model, 256, $system,
            'Call the "note" tool with arguments {"text":"benchmark"}.',
            function (string $raw) use ($registry) {
                try {
                    $decision = $this->parser->parse($raw, $registry);
                } catch (InvalidDecisionException $e) {
                    return [false, 'did not honour the tool-call envelope: '.$e->getMessage()];
                }

                if (! $decision instanceof ToolCall || $decision->tool !== 'note') {
                    return [false, 'parsed, but not the expected tool call'];
                }

                return [true, ''];
            },
        );
    }

    /** The dependency-style comprehension the supervisor relies on: one verifiable ordering question. */
    private function probeReasoning(string $model): array
    {
        $system = 'You are a careful planning assistant. Respond with EXACTLY ONE JSON object — no prose, no code fences.';
        $user = <<<'TXT'
        A project has two files: "outline.md" (the chapter plan) and "chapter1.md" (the
        first chapter, which must follow the plan laid out in outline.md). Which file
        must be written FIRST? Respond as {"first":"<filename>"}.
        TXT;

        return $this->probe('reasoning', $model, 64, $system, $user, function (string $raw) {
            $json = $this->extractJsonLoose($raw);
            $first = is_array($json) ? mb_strtolower(trim((string) ($json['first'] ?? ''))) : '';

            return [$first === 'outline.md', $first === '' ? 'no parseable {"first":...} answer' : "answered \"{$first}\""];
        });
    }

    /**
     * A needle-in-haystack: bury a fact at the very START of a ~8k-token filler
     * prompt and ask for it back. This is the num_ctx footgun detector from
     * CLAUDE.md — Ollama silently truncates to ~4k tokens by default, so a model
     * that passes ping/envelope/reasoning (short prompts) but loses the needle
     * here is very likely a GATEWAY CONFIG problem (num_ctx too low), not a bad
     * model. Only run when `deep` is requested — it's the slowest probe.
     */
    private function probeContext(string $model): array
    {
        $code = 'FB-'.mb_strtoupper(Str::random(6));
        // Deliberately imprecise — this only needs to comfortably clear the
        // common 4k default, not hit exactly 8000 tokens.
        $filler = str_repeat('The quick brown fox jumps over the lazy dog near the riverbank at dawn. ', 430);
        $system = 'You read a long document and answer with EXACTLY ONE JSON object — no prose, no code fences.';
        $user = "SECRET CODE: {$code}\n\n{$filler}\n\nWhat was the SECRET CODE given at the very start of this message? Respond as {\"secret\":\"<code>\"}.";

        return $this->probe('context', $model, 64, $system, $user, function (string $raw) use ($code) {
            $json = $this->extractJsonLoose($raw);
            $secret = is_array($json) ? trim((string) ($json['secret'] ?? '')) : '';
            $ok = $secret !== '' && strcasecmp($secret, $code) === 0;

            return [$ok, $ok ? '' : 'lost the needle — likely num_ctx truncating the prompt (see CLAUDE.md), not a bad model'];
        });
    }

    // ── Probe plumbing ───────────────────────────────────────────────────────

    /**
     * Run one probe: call the model with $system/$user, apply $check to the raw
     * text, and shape the result as {name, ok, ms, detail}. A throw or empty
     * completion is ok:false with the error text — it does NOT propagate, so
     * one bad probe can never abort the suite (ping is the sole exception,
     * handled by the caller).
     *
     * $answerTokens is what the ANSWER needs; REASONING_RESERVE is added on top
     * here, in one place, so no probe can accidentally be budgeted for its
     * answer alone and then be strangled mid-thought by a reasoning model.
     */
    private function probe(string $name, string $model, int $answerTokens, string $system, string $user, callable $check): array
    {
        $result = $this->callPinned($model, $answerTokens + self::REASONING_RESERVE, $system, $user);

        if ($result['error'] !== null) {
            return ['name' => $name, 'ok' => false, 'ms' => $result['ms'], 'detail' => $result['error']];
        }
        if (trim($result['raw']) === '') {
            // Name the likely cause instead of just "empty": at this budget it
            // means the model reasoned past even REASONING_RESERVE and never
            // reached an answer — a real finding about the model, not a dead
            // gateway, and the operator needs to be able to tell them apart.
            return ['name' => $name, 'ok' => false, 'ms' => $result['ms'], 'detail' => 'empty completion — produced no answer within '
                .($answerTokens + self::REASONING_RESERVE).' output tokens (a reasoning model that never stopped thinking?)'];
        }

        [$ok, $detail] = $check($result['raw']);

        return ['name' => $name, 'ok' => $ok, 'ms' => $result['ms'], 'detail' => $detail];
    }

    /**
     * ONE gateway call with the model + max_tokens pinned into config for the
     * duration of the call — the same trick ModelRouter::apply uses to route a
     * turn to a specific model, restored afterwards so a benchmark run never
     * leaks its pin into whatever job/turn runs next.
     */
    private function callPinned(string $model, int $maxTokens, string $system, string $user): array
    {
        $prevModel = config('research.llm.model');
        $prevMaxTokens = config('research.llm.max_tokens');
        config(['research.llm.model' => $model, 'research.llm.max_tokens' => $maxTokens]);

        $start = microtime(true);
        try {
            $raw = $this->llm->complete($system, [['role' => 'user', 'content' => $user]]);

            return ['raw' => $raw, 'ms' => $this->elapsedMs($start), 'error' => null];
        } catch (Throwable $e) {
            return ['raw' => '', 'ms' => $this->elapsedMs($start), 'error' => $e->getMessage()];
        } finally {
            config(['research.llm.model' => $prevModel, 'research.llm.max_tokens' => $prevMaxTokens]);
        }
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    /**
     * A permissive JSON pull for probes that check a single expected field
     * (ping/reasoning/context) — these are NOT testing the app's Decision
     * envelope, so they don't need DecisionParser's stricter contract. That
     * stricter path is reserved for the envelope probe, which specifically
     * tests whether THAT contract is honoured — reusing DecisionParser there
     * (rather than reimplementing a looser check) is what makes it trustworthy.
     */
    private function extractJsonLoose(string $raw): ?array
    {
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

    /** A minimal, do-nothing tool JUST for the envelope probe — never actually executed. */
    private function probeTool(): Tool
    {
        return new class implements Tool
        {
            public function name(): string
            {
                return 'note';
            }

            public function description(): string
            {
                return 'Record a short note.';
            }

            public function schema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => ['text' => ['type' => 'string']],
                    'required' => ['text'],
                    'additionalProperties' => false,
                ];
            }

            public function execute(ToolArguments $args, ResearchContext $context): ToolResult
            {
                return ToolResult::ok('noted'); // never called — the probe only checks parsing
            }
        };
    }

    // ── Scoring ───────────────────────────────────────────────────────────────

    /**
     * @param  list<array{name:string,ok:bool,ms:int,detail:string}>  $probes
     */
    private function score(string $model, array $probes, bool $lowConfidence): array
    {
        $byName = collect($probes)->keyBy('name');
        $ran = array_intersect_key(self::WEIGHTS, $byName->all());
        $totalWeight = array_sum($ran) ?: 1.0;

        $reliability = 0.0;
        foreach ($ran as $name => $weight) {
            if ($byName[$name]['ok']) {
                $reliability += $weight;
            }
        }
        $reliability /= $totalWeight;

        $mses = collect($probes)->pluck('ms')->filter(fn ($ms) => $ms !== null)->sort()->values()->all();
        $medianMs = empty($mses) ? null : (int) round($this->median($mses));

        // Speed is a modest TIEBREAK, never the deciding factor: even a maxed-out
        // 5-minute median only drags a perfect-reliability model down to a floor
        // of 60 (100 * 1.0 * 0.6) — comfortably above anything with a failed
        // envelope probe, whose reliability alone caps it well under that. See
        // the "broken" rule below for why envelope failure wins regardless.
        $speedFactor = $medianMs === null ? 1.0 : max(0.6, min(1.0, 1.0 - ($medianMs / 300000)));
        $score = (int) round(100 * $reliability * $speedFactor);

        $envelopeFailed = isset($byName['envelope']) && ! $byName['envelope']['ok'];
        $rating = match (true) {
            $envelopeFailed => 'broken', // cannot drive the agent — the score is irrelevant
            $score < 50 => 'weak',
            $score < 80 => 'usable',
            default => 'strong',
        };

        return [
            'status' => 'done',
            'score' => $score,
            'rating' => $rating,
            'median_ms' => $medianMs,
            'suggested_tier' => $this->suggestedTier($model, $rating, $reliability, $medianMs),
            'probes' => $probes,
            'low_confidence' => $lowConfidence,
            'error' => null,
        ];
    }

    private function median(array $sorted): float
    {
        $n = count($sorted);
        $mid = intdiv($n, 2);

        return $n % 2 === 0 ? ($sorted[$mid - 1] + $sorted[$mid]) / 2 : $sorted[$mid];
    }

    /**
     * A HINT shown in the UI — the caller (Settings) decides whether to act on
     * it; nothing here writes to config/settings.
     */
    private function suggestedTier(string $model, string $rating, float $reliability, ?int $medianMs): ?string
    {
        if ($rating === 'broken') {
            return null;
        }
        if ($reliability < 0.8) {
            return 'light'; // passes, but not reliably enough to trust with harder work
        }

        $fastMs = (int) config('research.gateway_load.fast_ms', 20000);
        if ($medianMs !== null && $medianMs <= $fastMs) {
            return $this->isFastestPassing($model, $medianMs) ? 'light' : 'standard';
        }

        return 'hard'; // slow, but highly reliable — worth the wait for the hard tier
    }

    /** Is $medianMs at least as fast as every OTHER non-broken benchmark on record? */
    private function isFastestPassing(string $model, int $medianMs): bool
    {
        $fastestOther = ModelBenchmarkRecord::query()
            ->where('model', '!=', $model)
            ->where('rating', '!=', 'broken')
            ->whereNotNull('median_ms')
            ->min('median_ms');

        return $fastestOther === null || $medianMs <= (int) $fastestOther;
    }
}
