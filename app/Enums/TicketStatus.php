<?php

namespace App\Enums;

enum TicketStatus: string
{
    /** Waiting for the support team. */
    case Open = 'open';

    /** Waiting for the customer; the resolution SLA clock is paused. */
    case Pending = 'pending';

    /** Solved; the customer can still reply to reopen it. */
    case Resolved = 'resolved';

    /** Final; closed automatically some days after resolution. */
    case Closed = 'closed';
}
