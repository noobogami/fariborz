<?php

namespace App\Domain\Research\Enums;

/**
 * The vocabulary of the research timeline.
 *
 * Every entry in `research_events` has one of these types. Together, in
 * sequence order, they tell the complete story of what the agent did:
 *
 *   job_started → thought → tool_selected → tool_started → tool_succeeded
 *   → observation → thought → ... → human_question_queued → ... → finished
 *
 * This is the enum the `research:trace` command reads to render the path.
 */
enum EventType: string
{
    case JobStarted = 'job_started';
    case Thought = 'thought';               // the agent's reasoning
    case ToolSelected = 'tool_selected';         // it decided which tool + args
    case ToolStarted = 'tool_started';
    case ToolSucceeded = 'tool_succeeded';
    case ToolFailed = 'tool_failed';
    case ToolRetried = 'tool_retried';
    case Observation = 'observation';           // the result fed back to the agent
    case GuardrailTriggered = 'guardrail_triggered';   // duplicate/failure/limit intercepted an action
    case HumanQuestionQueued = 'human_question_queued'; // no human available, queued
    case HumanQuestionAsked = 'human_question_asked';  // sent to an available human
    case HumanAnswerReceived = 'human_answer_received';
    case QuestionResolved = 'question_resolved';     // answered by another source / marked obsolete
    case QueueReconciled = 'queue_reconciled';      // human queue re-evaluated
    case InvalidLlmResponse = 'invalid_llm_response';  // the LLM broke the JSON contract
    case Finished = 'finished';              // final report produced
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Resumed = 'resumed';                // continued by a supervisor with new guidance

    /** A short glyph used by the trace renderer for scannability. */
    public function glyph(): string
    {
        return match ($this) {
            self::JobStarted => '▶',
            self::Thought => '🧠',
            self::ToolSelected => '🎯',
            self::ToolStarted => '⚙',
            self::ToolSucceeded => '🔧',
            self::ToolFailed => '💥',
            self::ToolRetried => '↻',
            self::Observation => '👁',
            self::GuardrailTriggered => '🛑',
            self::HumanQuestionQueued => '📥',
            self::HumanQuestionAsked => '🙋',
            self::HumanAnswerReceived => '💬',
            self::QuestionResolved => '✔',
            self::QueueReconciled => '🔁',
            self::InvalidLlmResponse => '⚠',
            self::Finished => '✅',
            self::Failed => '❌',
            self::Cancelled => '⏹',
            self::Resumed => '⤴',
        };
    }

    public function level(): string
    {
        return match ($this) {
            self::ToolFailed, self::Failed, self::InvalidLlmResponse => 'error',
            self::GuardrailTriggered, self::ToolRetried, self::Cancelled => 'warning',
            default => 'info',
        };
    }
}
