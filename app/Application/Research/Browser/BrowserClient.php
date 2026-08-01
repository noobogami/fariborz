<?php

namespace App\Application\Research\Browser;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Thin HTTP client for the Playwright browser microservice (services/browser).
 * Isolated so the tools and UI never talk to the service directly.
 */
class BrowserClient
{
    public function __construct(private Http $http) {}

    private function baseUrl(): string
    {
        return rtrim((string) config('research.browser.base_url'), '/');
    }

    private function timeout(): int
    {
        return (int) config('research.browser.request_timeout', 45);
    }

    /** Health probe for the dashboard. */
    public function status(): array
    {
        try {
            $res = $this->http->timeout(4)->get($this->baseUrl().'/health');

            return ['reachable' => $res->successful(), 'base_url' => $this->baseUrl()];
        } catch (Throwable $e) {
            return ['reachable' => false, 'base_url' => $this->baseUrl(), 'error' => $e->getMessage()];
        }
    }

    /** @return array{query:string,engine:string,results:array<int,array{title:string,url:string,snippet:string}>} */
    public function search(string $query, string $engine, int $limit): array
    {
        return $this->post('/search', ['query' => $query, 'engine' => $engine, 'limit' => $limit]);
    }

    /** @return array{url:string,title:string,text:string} */
    public function extract(string $url, int $maxChars = 8000): array
    {
        return $this->post('/extract', ['url' => $url, 'max_chars' => $maxChars]);
    }

    private function post(string $path, array $body): array
    {
        $res = $this->http->timeout($this->timeout())->asJson()->post($this->baseUrl().$path, $body);

        if ($res->failed()) {
            throw new BrowserServiceException($this->errorMessage($res));
        }

        return $res->json() ?? [];
    }

    private function errorMessage(Response $res): string
    {
        return $res->json('error')
            ?? "browser service returned HTTP {$res->status()}";
    }
}
