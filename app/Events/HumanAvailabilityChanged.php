<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when a human's status changes (e.g. comes online → reconcile queues). */
class HumanAvailabilityChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $humanId,
        public string $newStatus,
    ) {}
}
