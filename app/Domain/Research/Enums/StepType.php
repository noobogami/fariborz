<?php

namespace App\Domain\Research\Enums;

enum StepType: string
{
    case Thought = 'thought';     // the LLM's reasoning for this turn
    case Action = 'action';      // the tool call it chose
    case Observation = 'observation'; // the result Laravel fed back
    case Finish = 'finish';      // the final report decision
}
