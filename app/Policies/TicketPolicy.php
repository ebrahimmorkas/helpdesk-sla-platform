<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

/**
 * The organization scope already guarantees the ticket belongs to the user's
 * tenant; these rules separate staff from customers within it.
 */
class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        return $user->isStaff() || $ticket->requester_id === $user->id;
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $user->isStaff();
    }

    /** The activity timeline and SLA breaches are internal. */
    public function viewInternals(User $user, Ticket $ticket): bool
    {
        return $user->isStaff();
    }
}
