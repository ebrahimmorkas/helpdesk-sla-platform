<?php

namespace App\Enums;

enum Role: string
{
    /** Manages the organization: users, SLA policies, business hours. Also works tickets. */
    case Admin = 'admin';

    /** Support staff working tickets. */
    case Agent = 'agent';

    /** End customer who raises tickets and sees only their own. */
    case Customer = 'customer';

    public function isStaff(): bool
    {
        return $this !== self::Customer;
    }
}
