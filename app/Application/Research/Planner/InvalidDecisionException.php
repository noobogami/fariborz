<?php

namespace App\Application\Research\Planner;

use RuntimeException;

/** Thrown when the LLM's output does not satisfy the JSON contract. */
class InvalidDecisionException extends RuntimeException {}
