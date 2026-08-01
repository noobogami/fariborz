<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Tools\RetryableToolException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use Illuminate\Http\Client\Factory as Http;

/** Free, keyless programming Q&A via the public Stack Exchange API. */
class StackOverflowTool implements Tool
{
    public function __construct(private Http $http) {}

    public function name(): string
    {
        return 'stackoverflow_search';
    }

    public function description(): string
    {
        return 'Search Stack Overflow for programming/technical questions and see the '
            .'top matches (title, score, whether answered, link). Free, no API key. Use '
            .'for coding problems, error messages, and library/how-to questions.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'num_results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        $query = $args->string('query');
        $num = max(1, min(10, $args->int('num_results', 5)));

        $res = $this->http->timeout(15)->get('https://api.stackexchange.com/2.3/search/advanced', [
            'order' => 'desc', 'sort' => 'relevance', 'q' => $query,
            'site' => 'stackoverflow', 'pagesize' => $num,
        ]);

        if ($res->serverError() || $res->status() === 429) {
            throw new RetryableToolException("Stack Exchange API returned {$res->status()}");
        }
        if ($res->failed()) {
            return ToolResult::fail("Stack Exchange API error {$res->status()}");
        }

        $items = collect($res->json('items', []))->map(fn ($i) => [
            'title' => html_entity_decode($i['title'] ?? '', ENT_QUOTES),
            'link' => $i['link'] ?? '',
            'score' => $i['score'] ?? 0,
            'is_answered' => $i['is_answered'] ?? false,
            'answer_count' => $i['answer_count'] ?? 0,
        ])->all();

        if (empty($items)) {
            return ToolResult::ok("No Stack Overflow results for \"{$query}\".", ['query' => $query]);
        }

        $obs = collect($items)->map(fn ($i, $n) => sprintf(
            "%d. %s  [score %d, %s, %d answers]\n   %s",
            $n + 1, $i['title'], $i['score'], $i['is_answered'] ? 'answered' : 'unanswered', $i['answer_count'], $i['link']
        ))->implode("\n");

        return ToolResult::ok("Stack Overflow results for \"{$query}\":\n{$obs}", ['query' => $query, 'results' => $items]);
    }
}
