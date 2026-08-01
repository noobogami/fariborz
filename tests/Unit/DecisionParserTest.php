<?php

namespace Tests\Unit;

use App\Application\Research\Planner\DecisionParser;
use App\Application\Research\Planner\InvalidDecisionException;
use App\Application\Research\Tools\ToolRegistry;
use App\Domain\Research\ValueObjects\FinishDecision;
use App\Domain\Research\ValueObjects\ToolCall;
use App\Infrastructure\Research\Tools\CalculatorTool;
use PHPUnit\Framework\TestCase;

class DecisionParserTest extends TestCase
{
    private function registry(): ToolRegistry
    {
        return new ToolRegistry([new CalculatorTool]);
    }

    public function test_parses_a_tool_call(): void
    {
        $raw = '{"thought":"do math","action":"tool","tool":"calculator","arguments":{"expression":"2+2"}}';

        $decision = (new DecisionParser)->parse($raw, $this->registry());

        $this->assertInstanceOf(ToolCall::class, $decision);
        $this->assertSame('calculator', $decision->tool);
        $this->assertSame('2+2', $decision->arguments['expression']);
    }

    public function test_parses_a_finish_wrapped_in_code_fences(): void
    {
        $raw = "Sure!\n```json\n{\"action\":\"finish\",\"report\":\"done\",\"confidence\":0.9}\n```";

        $decision = (new DecisionParser)->parse($raw, $this->registry());

        $this->assertInstanceOf(FinishDecision::class, $decision);
        $this->assertSame('done', $decision->report);
        $this->assertSame(0.9, $decision->confidence);
    }

    public function test_recovers_when_action_names_a_tool_directly(): void
    {
        // Weak models collapse {"action":"tool","tool":"calculator"} into
        // {"action":"calculator"} — the parser should route it, not reject it.
        $raw = '{"thought":"do math","action":"calculator","arguments":{"expression":"2+2"}}';

        $decision = (new DecisionParser)->parse($raw, $this->registry());

        $this->assertInstanceOf(ToolCall::class, $decision);
        $this->assertSame('calculator', $decision->tool);
        $this->assertSame('2+2', $decision->arguments['expression']);
    }

    public function test_recovers_when_args_are_at_the_top_level(): void
    {
        // Model names the tool as the action AND drops args at the top level.
        $raw = '{"action":"calculator","expression":"2+2"}';

        $decision = (new DecisionParser)->parse($raw, $this->registry());

        $this->assertInstanceOf(ToolCall::class, $decision);
        $this->assertSame('calculator', $decision->tool);
        $this->assertSame('2+2', $decision->arguments['expression']);
    }

    public function test_rejects_unknown_tool(): void
    {
        $this->expectException(InvalidDecisionException::class);

        (new DecisionParser)->parse(
            '{"action":"tool","tool":"nope","arguments":{}}',
            $this->registry(),
        );
    }

    public function test_rejects_arguments_that_violate_schema(): void
    {
        $this->expectException(InvalidDecisionException::class);

        // calculator requires "expression"
        (new DecisionParser)->parse(
            '{"action":"tool","tool":"calculator","arguments":{"wrong":"x"}}',
            $this->registry(),
        );
    }
}
