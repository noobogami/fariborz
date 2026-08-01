<?php

namespace App\Infrastructure\Research\Tools;

use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;

/**
 * Example tool #3 — the "hello world" of tools, showing that not every tool
 * needs I/O or dependencies. Safely evaluates a basic arithmetic expression.
 */
class CalculatorTool implements Tool
{
    public function name(): string
    {
        return 'calculator';
    }

    public function description(): string
    {
        return 'Evaluate a basic arithmetic expression (+ - * / parentheses, decimals). '
            .'Use for exact math instead of estimating in your head.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'expression' => ['type' => 'string', 'description' => 'e.g. "1200000 / 350"'],
            ],
            'required' => ['expression'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        $expr = $args->string('expression');

        if (! preg_match('/^[0-9+\-*\/().\s]+$/', $expr)) {
            return ToolResult::fail('Expression contains unsupported characters. Only digits and + - * / ( ) . are allowed.');
        }

        try {
            $value = $this->evaluate($expr);
        } catch (\Throwable $e) {
            return ToolResult::fail("Could not evaluate expression: {$e->getMessage()}");
        }

        return ToolResult::ok("{$expr} = {$value}", ['expression' => $expr, 'value' => $value]);
    }

    /** A tiny shunting-yard evaluator — no eval(), safe on untrusted input. */
    private function evaluate(string $expr): float
    {
        $tokens = preg_split('/\s*([+\-*\/()])\s*/', $expr, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        $prec = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];
        $output = [];
        $ops = [];

        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }
            if (is_numeric($tok)) {
                $output[] = (float) $tok;
            } elseif (isset($prec[$tok])) {
                while ($ops && end($ops) !== '(' && $prec[end($ops)] >= $prec[$tok]) {
                    $output[] = array_pop($ops);
                }
                $ops[] = $tok;
            } elseif ($tok === '(') {
                $ops[] = $tok;
            } elseif ($tok === ')') {
                while ($ops && end($ops) !== '(') {
                    $output[] = array_pop($ops);
                }
                array_pop($ops);
            }
        }
        while ($ops) {
            $output[] = array_pop($ops);
        }

        $stack = [];
        foreach ($output as $tok) {
            if (is_float($tok)) {
                $stack[] = $tok;

                continue;
            }
            $b = array_pop($stack);
            $a = array_pop($stack);
            $stack[] = match ($tok) {
                '+' => $a + $b,
                '-' => $a - $b,
                '*' => $a * $b,
                '/' => $b == 0.0 ? throw new \RuntimeException('division by zero') : $a / $b,
            };
        }

        return (float) ($stack[0] ?? 0);
    }
}
