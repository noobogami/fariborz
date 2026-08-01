<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Tools\RetryableToolException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use Illuminate\Http\Client\Factory as Http;

/**
 * Web search via the Brave Search API — independent index, good general-purpose
 * results. Requires BRAVE_API_KEY; free tier is ~2,000 queries/month. Registered
 * only when the key is set (see ResearchServiceProvider).
 */
class BraveSearchTool implements Tool
{
    public function __construct(private Http $http) {}

    public function name(): string
    {
        return 'brave_search';
    }

    public function description(): string
    {
        return 'General-purpose web search (Brave) returning ranked results (title, '
            .'snippet, url). Use for public facts, news, companies, and background. Read '
            .'a result in full with read_webpage.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'The search query.'],
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

        $res = $this->http
            ->timeout(30)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Subscription-Token' => (string) config('services.brave.key'),
            ])
            ->get('https://api.search.brave.com/res/v1/web/search', [
                'q' => $query, 'count' => $num,
            ]);

        if ($res->serverError() || $res->status() === 429) {
            throw new RetryableToolException("Brave API returned {$res->status()}");
        }
        if ($res->failed()) {
            return ToolResult::fail("Brave API error {$res->status()}: ".$res->body());
        }

        $results = collect($res->json('web.results', []))->take($num)->map(fn ($r) => [
            'title' => $r['title'] ?? '',
            'url' => $r['url'] ?? '',
            'snippet' => strip_tags($r['description'] ?? ''),
        ])->all();

        if (empty($results)) {
            return ToolResult::ok("No Brave results for \"{$query}\".", ['query' => $query, 'results' => []]);
        }

        $obs = collect($results)
            ->map(fn ($r, $i) => sprintf("%d. %s\n   %s\n   %s", $i + 1, $r['title'], $r['snippet'], $r['url']))
            ->implode("\n");

        return ToolResult::ok("Web results for \"{$query}\" (Brave):\n{$obs}", ['query' => $query, 'results' => $results]);
    }
}
