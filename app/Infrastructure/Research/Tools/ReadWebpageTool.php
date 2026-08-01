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
 * Reads a specific public web page (rendered in a real browser) and returns its
 * readable text. Pairs with browser_search: search → pick a url → read it.
 */
class ReadWebpageTool implements Tool
{
    public function __construct(private BrowserClient $browser) {}

    public function name(): string
    {
        return 'read_webpage';
    }

    public function description(): string
    {
        return 'Open a public web page in a headless browser and return its readable text '
            .'AND the links on it (no API key). Read like a human: after reading, pick the '
            .'most relevant link and call read_webpage on it to go deeper — search → read → '
            .'follow a link → read → repeat until you have the answer.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'Absolute http(s) URL to read.'],
                'max_chars' => ['type' => 'integer', 'minimum' => 500, 'maximum' => 20000],
            ],
            'required' => ['url'],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolArguments $args, ResearchContext $ctx): ToolResult
    {
        $url = $args->string('url');
        $max = max(500, min(20000, $args->int('max_chars', 8000)));

        try {
            $data = $this->browser->extract($url, $max);
        } catch (BrowserServiceException $e) {
            throw new RetryableToolException($e->getMessage());
        }

        $title = $data['title'] ?? '';
        $text = trim($data['text'] ?? '');
        $links = $data['links'] ?? [];

        if ($text === '' && empty($links)) {
            return ToolResult::ok("The page at {$url} had no readable text.", ['url' => $url]);
        }

        $observation = "Page: {$title}\nURL: {$url}\n\n{$text}";

        // Hand back the on-page links so the agent can follow one next (like a human).
        if (! empty($links)) {
            $list = collect($links)->take(20)
                ->map(fn ($l, $i) => sprintf('%d. %s — %s', $i + 1, $l['text'] ?? '', $l['url'] ?? ''))
                ->implode("\n");
            $observation .= "\n\nLINKS ON THIS PAGE (call read_webpage on the most relevant to go deeper):\n{$list}";
        }

        return ToolResult::ok(
            $observation,
            ['url' => $url, 'title' => $title, 'chars' => mb_strlen($text), 'links' => $links],
        );
    }
}
