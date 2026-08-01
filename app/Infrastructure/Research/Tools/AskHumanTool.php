<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Human\HumanAvailabilityService;
use App\Domain\Research\Contracts\HumanQuestionRepository;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\Contracts\TraceRecorder;
use App\Domain\Research\Enums\EventType;
use App\Domain\Research\Enums\QuestionStatus;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use App\Events\HumanQuestionAsked;
use App\Models\HumanQuestion;

/**
 * Example tool #2 — the asynchronous, availability-aware human escalation.
 *
 * The golden rule: this tool NEVER blocks. It either dispatches the question to
 * an available expert or queues it, and in BOTH cases returns immediately with
 * a "deferred" observation telling the agent to keep working via other tools.
 * The answer (whenever it arrives) is injected into memory later and the loop
 * resumes — see ResumeResearchOnHumanAnswer.
 */
class AskHumanTool implements Tool
{
    public function __construct(
        private HumanAvailabilityService $availability,
        private HumanQuestionRepository $questions,
        private TraceRecorder $trace,
    ) {}

    public function name(): string
    {
        return 'ask_human';
    }

    public function description(): string
    {
        $name = config('research.human.name', 'Father');

        return "Ask {$name} — the single human overseeing this research — something no "
            .'automated tool can answer (private/internal context, a judgment call). '
            ."EXPENSIVE and SLOW; use only after other tools are insufficient. {$name} may "
            .'be available or not; if not, the question is QUEUED and you must KEEP '
            .'RESEARCHING with other tools — you are never blocked waiting. Do not ask the '
            .'same question twice.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => ['type' => 'string'],
                'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            ],
            'required' => ['question'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        // Dedup: never re-submit a question the agent already asked. If it was
        // answered, hand back the answer; if it's still pending, say so. Either
        // way, no new row is created.
        if ($existing = $this->questions->existingFor($ctx->jobId(), $args->string('question'))) {
            return $this->handleDuplicate($ctx, $existing);
        }

        // Persist first — the question must survive regardless of availability.
        $question = $this->questions->create(
            jobId: $ctx->jobId(),
            question: $args->string('question'),
            tags: [],
            priority: $args->int('priority', 5),
        );

        $responder = $this->availability->pickResponder();

        if ($responder !== null) {
            $this->questions->assign($question, $responder, status: 'asked');
            event(new HumanQuestionAsked($question->id, $responder->id));

            $this->trace->record($ctx->job, EventType::HumanQuestionAsked,
                "Asked {$responder->name}: \"{$question->question}\"",
                ['question_id' => $question->id, 'assigned_to' => $responder->id]);

            return ToolResult::deferred(
                "Question sent to {$responder->name} (available). Their answer will appear as a "
                .'later observation. Continue researching other things meanwhile.',
                ['question_id' => $question->id, 'assigned_to' => $responder->id],
            );
        }

        // Not available — queue and instruct the agent to route around it.
        $this->questions->markQueued($question);
        $name = $this->availability->name();

        $this->trace->record($ctx->job, EventType::HumanQuestionQueued,
            "Queued ({$this->availability->summary()}): \"{$question->question}\"",
            ['question_id' => $question->id, 'human_status' => $this->availability->summary()]);

        return ToolResult::deferred(
            "{$name} is not available right now ({$this->availability->summary()}). Question "
            ."#{$question->id} was QUEUED. Do NOT wait — try to answer it yourself using other "
            .'tools (web search, reading pages, wikipedia, etc.). If you find a confident answer '
            .'from another source, the queued question resolves automatically.',
            ['question_id' => $question->id, 'queued' => true],
        );
    }

    /** The agent asked something it already asked — return status/answer, no new row. */
    private function handleDuplicate(ResearchContext $ctx, HumanQuestion $existing): ToolResult
    {
        $this->trace->record($ctx->job, EventType::GuardrailTriggered,
            "Suppressed duplicate human question (already #{$existing->id}, {$existing->status->value})",
            ['question_id' => $existing->id, 'status' => $existing->status->value]);

        // Already answered → give the agent the answer so it stops asking.
        if (in_array($existing->status, [QuestionStatus::Answered, QuestionStatus::ResolvedByOther], true)) {
            return ToolResult::ok(
                "You already asked this and it was answered: \"{$existing->answer}\". "
                .'Use that answer — do NOT ask again.',
                ['question_id' => $existing->id, 'answer' => $existing->answer, 'duplicate' => true],
            );
        }

        // Still open → tell it to move on, don't create another copy.
        return ToolResult::deferred(
            "You have ALREADY asked this exact question (#{$existing->id}, status: "
            ."{$existing->status->value}) and it is not answered yet. Do NOT ask it again. "
            .'Continue researching other aspects with other tools, or finish if you have enough.',
            ['question_id' => $existing->id, 'status' => $existing->status->value, 'duplicate' => true],
        );
    }
}
