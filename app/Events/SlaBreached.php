<?php

namespace App\Events;

use App\Enums\SlaMetric;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Carries ids and the tenant explicitly: listeners run on the queue, where
 * there is no request and therefore no organization in context.
 */
class SlaBreached
{
    use Dispatchable;

    public function __construct(
        public readonly int $ticketId,
        public readonly int $organizationId,
        public readonly SlaMetric $metric,
    ) {}
}
