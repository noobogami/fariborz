<?php

namespace App\Domain\Research\Enums;

enum QuestionStatus: string
{
    case Queued = 'queued';            // waiting because no human was available
    case Asked = 'asked';             // sent to a human, awaiting their answer
    case Answered = 'answered';          // a human answered it
    case ResolvedByOther = 'resolved_by_other'; // answered with confidence by another tool
    case Obsolete = 'obsolete';          // no longer needed for the research
    case Cancelled = 'cancelled';
}
