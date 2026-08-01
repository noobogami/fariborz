<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ResearchFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $jobId,
        public string $reason,
    ) {}
}
