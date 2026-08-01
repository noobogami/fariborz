<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when a question is dispatched to an available human (notify them here). */
class HumanQuestionAsked
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $questionId,
        public string $humanId,
    ) {}
}
