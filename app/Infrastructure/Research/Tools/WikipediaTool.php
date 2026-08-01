<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Tools\RetryableToolException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use Illuminate\Http\Client\Factory as Http;

/** Free, keyless encyclopedic lookups via the public Wikipedia API. */
class WikipediaTool implements Tool
{
    public function __construct(private Http $http) {}

    public function name(): string
    {
        return 'wikipedia';
    }

    public function description(): string
    {
        return 'Look up a topic on Wikipedia and get a concise factual summary of the '
            .'best-matching article (free, no API key). Great for definitions, people, '
            .'companies, places, and background facts.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'What to look up.'],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        $query = $args->string('query');
        $api = 'https://en.wikipedia.org/w/api.php';

        $search = $this->http->timeout(15)->get($api, [
            'action' => 'query', 'list' => 'search', 'srsearch' => $query,
            'srlimit' => 1, 'format' => 'json',
        ]);

        if ($search->serverError() || $search->status() === 429) {
            throw new RetryableToolException("Wikipedia API returned {$search->status()}");
        }

        $title = $search->json('query.search.0.title');
        if (! $title) {
            return ToolResult::ok("No Wikipedia article found for \"{$query}\".", ['query' => $query]);
        }

        $extract = $this->http->timeout(15)->get($api, [
            'action' => 'query', 'prop' => 'extracts', 'exintro' => 1, 'explaintext' => 1,
            'redirects' => 1, 'titles' => $title, 'format' => 'json',
        ]);

        $pages = $extract->json('query.pages', []);
        $page = is_array($pages) ? reset($pages) : [];
        $summary = trim($page['extract'] ?? '');
        $url = 'https://en.wikipedia.org/wiki/'.str_replace(' ', '_', $title);

        if ($summary === '') {
            return ToolResult::ok("Found the article \"{$title}\" but it had no summary. {$url}", ['title' => $title, 'url' => $url]);
        }

        return ToolResult::ok(
            "Wikipedia — {$title}:\n".mb_substr($summary, 0, 2000)."\n\nSource: {$url}",
            ['title' => $title, 'url' => $url, 'summary' => $summary],
        );
    }
}
