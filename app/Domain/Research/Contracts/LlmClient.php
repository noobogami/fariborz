<?php

namespace App\Domain\Research\Contracts;

interface LlmClient
{
    /**
     * Send the system prompt + transcript and return the raw text completion.
     *
     * @param  string  $system  the persistent system prompt (contract + rules)
     * @param  array<int, array{role:string, content:string}>  $messages
     * @param  ?callable(array{content:string, thinking:string}):void  $onProgress
     *                                                                              optional; called with the accumulated partial output as it streams
     *                                                                              — `content` is the answer so far, `thinking` is any reasoning-model
     *                                                                              chain-of-thought — so the UI can show the model "thinking" live.
     *                                                                              Adapters that can't stream may ignore it.
     * @return string raw model output (the planner parses it into a Decision)
     */
    public function complete(string $system, array $messages, ?callable $onProgress = null): string;
}
