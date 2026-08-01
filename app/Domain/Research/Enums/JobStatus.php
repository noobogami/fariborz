<?php

namespace App\Domain\Research\Enums;

enum JobStatus: string
{
    case Pending = 'pending';    // created, not yet started
    case Running = 'running';    // actively iterating
    case Completed = 'completed';  // finished with a report
    case Failed = 'failed';     // errored out
    case Cancelled = 'cancelled';  // stopped by a supervisor

    /** Whether the orchestrator is allowed to advance a job in this status. */
    public function isRunnable(): bool
    {
        return $this === self::Running;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
