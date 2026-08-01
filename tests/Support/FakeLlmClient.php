<?php

namespace Tests\Support;

use App\Domain\Research\Contracts\LlmClient;

/**
 * A scripted LLM for tests. Feed it the exact JSON decisions the agent should
 * make, in order, and assert the orchestrator drives the loop correctly —
 * with zero network calls. This is why LlmClient is an interface.
 */
class FakeLlmClient implements LlmClient
{
    private int $cursor = 0;

    /** @param array<int,string|array> $scriptedResponses */
    public function __construct(private array $scriptedResponses) {}

    public function complete(string $system, array $messages, ?callable $onProgress = null): string
    {
        $response = $this->scriptedResponses[$this->cursor] ?? end($this->scriptedResponses);
        $this->cursor++;

        $raw = is_array($response) ? json_encode($response) : $response;

        if ($onProgress) {
            // Mirror the streaming client's shape: {content, thinking}.
            $onProgress(['content' => $raw, 'thinking' => '']);
        }

        return $raw;
    }
}
