<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Tools\RetryableToolException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;
use Illuminate\Http\Client\Factory as Http;

/** Free, keyless academic-paper search via the public arXiv API (Atom feed). */
class ArxivTool implements Tool
{
    public function __construct(private Http $http) {}

    public function name(): string
    {
        return 'arxiv_search';
    }

    public function description(): string
    {
        return 'Search arXiv for scientific/technical papers (free, no API key). Returns '
            .'title, authors, abstract, and link. Use for research-grade sources on ML, '
            .'physics, math, CS, etc.';
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

        $res = $this->http->timeout(20)->get('https://export.arxiv.org/api/query', [
            'search_query' => 'all:'.$query, 'start' => 0, 'max_results' => $num,
        ]);

        if ($res->serverError() || $res->status() === 429) {
            throw new RetryableToolException("arXiv API returned {$res->status()}");
        }
        if ($res->failed()) {
            return ToolResult::fail("arXiv API error {$res->status()}");
        }

        $xml = @simplexml_load_string($res->body());
        if ($xml === false) {
            return ToolResult::fail('Could not parse the arXiv response.');
        }

        $papers = [];
        foreach ($xml->entry ?? [] as $entry) {
            $authors = [];
            foreach ($entry->author ?? [] as $a) {
                $authors[] = trim((string) $a->name);
            }
            $papers[] = [
                'title' => trim(preg_replace('/\s+/', ' ', (string) $entry->title)),
                'authors' => array_slice($authors, 0, 5),
                'abstract' => trim(preg_replace('/\s+/', ' ', (string) $entry->summary)),
                'url' => trim((string) $entry->id),
            ];
        }

        if (empty($papers)) {
            return ToolResult::ok("No arXiv papers found for \"{$query}\".", ['query' => $query]);
        }

        $obs = collect($papers)->map(fn ($p, $n) => sprintf(
            "%d. %s\n   by %s\n   %s\n   %s",
            $n + 1, $p['title'], implode(', ', $p['authors']), mb_substr($p['abstract'], 0, 300).'…', $p['url']
        ))->implode("\n\n");

        return ToolResult::ok("arXiv results for \"{$query}\":\n{$obs}", ['query' => $query, 'results' => $papers]);
    }
}
