<?php

namespace App\Application\Research\Tools;

use RuntimeException;

/**
 * Tools throw this for TRANSIENT failures (network blips, 5xx, rate limits)
 * that the ToolRunner should retry with backoff. Any other exception is treated
 * as fatal for that call (no retry) and reported back as an observation.
 */
class RetryableToolException extends RuntimeException {}
