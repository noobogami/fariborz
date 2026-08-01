<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Tools\RetryableToolException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use Illuminate\Http\Client\Factory as Http;

/**
 * Web search via Tavily — a search API built for AI agents (clean, ranked
 * results with content snippets, and an optional synthesized answer). Requires
 * TAVILY_API_KEY; free tier is ~1,000 searches/month. Registered only when the
 * key is set (see ResearchServiceProvider).
 */
class TavilySearchTool implements Tool
{
    public function __construct(private Http $http) {}

    public function name(): string
    {
        return 'tavily_search';
    }

    public function description(): string
    {
        return 'High-quality web search (Tavily) that returns ranked results with '
            .'content snippets and a short synthesized answer. Best tool for open-ended '
            .'research questions: news, companies, facts, comparisons. Cite the urls.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'The search query.'],
                'max_results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        $query = $args->string('query');
        $max = max(1, min(10, $args->int('max_results', 5)));

        $res = $this->http->timeout(30)->asJson()->post('https://api.tavily.com/search', [
            'api_key' => config('services.tavily.key'),
            'query' => $query,
            'max_results' => $max,
            'search_depth' => 'basic',
            'include_answer' => true,
        ]);

        if ($res->serverError() || $res->status() === 429) {
            throw new RetryableToolException("Tavily API returned {$res->status()}");
        }
        if ($res->failed()) {
            return ToolResult::fail("Tavily API error {$res->status()}: ".$res->body());
        }

        $results = collect($res->json('results', []))->map(fn ($r) => [
            'title' => $r['title'] ?? '',
            'url' => $r['url'] ?? '',
            'content' => $r['content'] ?? '',
        ])->all();

        $answer = trim((string) $res->json('answer'));

        if (empty($results) && $answer === '') {
            return ToolResult::ok("No Tavily results for \"{$query}\".", ['query' => $query, 'results' => []]);
        }

        $obs = $answer !== '' ? "Answer: {$answer}\n\nSources:\n" : "Results for \"{$query}\":\n";
        $obs .= collect($results)
            ->map(fn ($r, $i) => sprintf("%d. %s\n   %s\n   %s", $i + 1, $r['title'], mb_substr($r['content'], 0, 240), $r['url']))
            ->implode("\n");

        return ToolResult::ok($obs, ['query' => $query, 'answer' => $answer, 'results' => $results]);
    }
}
