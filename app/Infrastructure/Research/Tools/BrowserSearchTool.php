<?php

namespace App\Infrastructure\Research\Tools;

use App\Application\Research\Browser\BrowserClient;
use App\Application\Research\Browser\BrowserServiceException;
use App\Application\Research\Tools\RetryableToolException;
use App\Domain\Research\Contracts\Tool;
use App\Domain\Research\ValueObjects\ResearchContext;
use App\Domain\Research\ValueObjects\ToolArguments;
use App\Domain\Research\ValueObjects\ToolResult;

/**
 * Free web search via a real headless browser (no API key). Uses the Playwright
 * microservice, so it works with the public search UI the same way a person
 * would. This is the keyless alternative to google_search (which needs SerpAPI).
 */
class BrowserSearchTool implements Tool
{
    public function __construct(private BrowserClient $browser) {}

    public function name(): string
    {
        return 'browser_search';
    }

    public function description(): string
    {
        return 'Search the web — NO API KEY needed. Returns top results (title, snippet, '
            .'url) using a keyless search backend. Prefer this for public facts (news, '
            .'companies, definitions, background). Use read_webpage to read a result in full.';
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
        $engine = (string) config('research.browser.default_engine', 'duckduckgo');
        $num = max(1, min(10, $args->int('num_results', 5)));

        try {
            $data = $this->browser->search($query, $engine, $num);
        } catch (BrowserServiceException $e) {
            // Transient headless/nav failures are worth one retry via the runner.
            throw new RetryableToolException($e->getMessage());
        }

        $results = $data['results'] ?? [];

        if (empty($results)) {
            return ToolResult::ok(
                "No web results for \"{$query}\". Try wikipedia, or rephrase the query.",
                ['query' => $query, 'results' => []],
            );
        }

        $observation = collect($results)
            ->map(fn ($r, $i) => sprintf("%d. %s\n   %s\n   %s", $i + 1, $r['title'] ?? '', $r['snippet'] ?? '', $r['url'] ?? ''))
            ->implode("\n");

        return ToolResult::ok(
            "Web results for \"{$query}\":\n{$observation}",
            ['query' => $query, 'results' => $results],
        );
    }
}
