<?php

namespace App\Domain\Research\Enums;

enum HumanStatus: string
{
    case Available = 'available';
    case Busy = 'busy';
    case Away = 'away';
    case Offline = 'offline';
}
