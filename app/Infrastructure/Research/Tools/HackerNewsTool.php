<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Tools\RetryableToolException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use Illuminate\Http\Client\Factory as Http;

/** Free, keyless tech-news/discussion search via the Hacker News (Algolia) API. */
class HackerNewsTool implements Tool
{
    public function __construct(private Http $http) {}

    public function name(): string
    {
        return 'hackernews_search';
    }

    public function description(): string
    {
        return 'Search Hacker News stories & discussions (free, no API key). Good for '
            .'tech opinions, tooling comparisons, launch news, and community sentiment.';
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

        $res = $this->http->timeout(15)->get('https://hn.algolia.com/api/v1/search', [
            'query' => $query, 'tags' => 'story', 'hitsPerPage' => $num,
        ]);

        if ($res->serverError() || $res->status() === 429) {
            throw new RetryableToolException("HN API returned {$res->status()}");
        }

        $hits = collect($res->json('hits', []))->map(fn ($h) => [
            'title' => $h['title'] ?? ($h['story_title'] ?? ''),
            'url' => $h['url'] ?: ('https://news.ycombinator.com/item?id='.($h['objectID'] ?? '')),
            'points' => $h['points'] ?? 0,
            'comments' => $h['num_comments'] ?? 0,
            'hn' => 'https://news.ycombinator.com/item?id='.($h['objectID'] ?? ''),
        ])->filter(fn ($h) => $h['title'] !== '')->values()->all();

        if (empty($hits)) {
            return ToolResult::ok("No Hacker News stories for \"{$query}\".", ['query' => $query]);
        }

        $obs = collect($hits)->map(fn ($h, $n) => sprintf(
            "%d. %s  [%d points, %d comments]\n   %s\n   discussion: %s",
            $n + 1, $h['title'], $h['points'], $h['comments'], $h['url'], $h['hn']
        ))->implode("\n");

        return ToolResult::ok("Hacker News results for \"{$query}\":\n{$obs}", ['query' => $query, 'results' => $hits]);
    }
}
