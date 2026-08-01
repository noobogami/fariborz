<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ResearchCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $jobId,
        public bool $partial = false,
    ) {}
}
