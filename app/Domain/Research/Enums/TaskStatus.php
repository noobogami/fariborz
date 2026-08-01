<?php

namespace App\Domain\Research\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';               // not started
    case InProgress = 'in_progress';        // a worker is running it
    case AwaitingReview = 'awaiting_review'; // worker finished; supervisor must verify
    case Done = 'done';                     // verified & accepted
    case Failed = 'failed';                 // gave up on it

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::InProgress, self::AwaitingReview], true);
    }
}
