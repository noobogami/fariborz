<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Tools\RetryableToolException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use Illuminate\Http\Client\Factory as Http;

/**
 * Example tool #1. A self-contained web search. Note how little there is to a
 * tool: name + description + schema + execute. This is the whole extension
 * surface — copy this file to add another capability.
 */
class GoogleSearchTool implements Tool
{
    public function __construct(private Http $http) {}

    public function name(): string
    {
        return 'google_search';
    }

    public function description(): string
    {
        return 'Search the public web for a query and get the top organic results '
            .'(title, snippet, url). Use for public facts: company revenue, news, '
            .'reviews, filings, general background.';
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

        $response = $this->http
            ->timeout(20)
            ->get('https://serpapi.com/search', [
                'engine' => 'google',
                'q' => $query,
                'num' => $num,
                'api_key' => config('services.serpapi.key'),
            ]);

        // Treat transient upstream problems as retryable; the runner backs off.
        if ($response->serverError() || $response->status() === 429) {
            throw new RetryableToolException("Search API returned {$response->status()}");
        }

        if ($response->failed()) {
            return ToolResult::fail("Search API error {$response->status()}: ".$response->body());
        }

        $results = collect($response->json('organic_results', []))
            ->take($num)
            ->map(fn ($r) => [
                'title' => $r['title'] ?? '',
                'snippet' => $r['snippet'] ?? '',
                'url' => $r['link'] ?? '',
            ])
            ->values()
            ->all();

        if (empty($results)) {
            return ToolResult::ok("No results found for \"{$query}\".", ['query' => $query, 'results' => []]);
        }

        $observation = collect($results)
            ->map(fn ($r, $i) => sprintf("%d. %s\n   %s\n   %s", $i + 1, $r['title'], $r['snippet'], $r['url']))
            ->implode("\n");

        return ToolResult::ok(
            "Search results for \"{$query}\":\n{$observation}",
            ['query' => $query, 'results' => $results],
        );
    }
}
