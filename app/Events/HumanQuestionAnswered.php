<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when a human submits an answer (out of band, e.g. via HTTP). */
class HumanQuestionAnswered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $questionId,
    ) {}
}
