<?php

namespace Keel\App\Services\Providers;

/**
 * Raised when a feed request fails or returns something that cannot be trusted.
 * The service treats this as "no data for this batch" - never as zeros.
 */
class ScoreFeedException extends \RuntimeException
{
}
