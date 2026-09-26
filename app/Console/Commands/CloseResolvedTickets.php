<?php

namespace App\Console\Commands;

use App\Enums\ActivityType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\OrganizationScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Resolved tickets the customer did not reply to are closed after a grace
 * period. Runs across all tenants, recording the change in each timeline.
 */
#[Signature('tickets:close-resolved {--days=7 : Days after resolution before closing}')]
#[Description('Close tickets that have been resolved for longer than the grace period')]
class CloseResolvedTickets extends Command
{
    public function handle(CurrentOrganization $context): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        $closed = 0;

        Ticket::withoutGlobalScope(OrganizationScope::class)
            ->where('status', TicketStatus::Resolved)
            ->where('resolved_at', '<=', $cutoff)
            ->chunkById(500, function ($tickets) use ($context, &$closed) {
                foreach ($tickets as $ticket) {
                    $closed += $context->run($ticket->organization_id, fn () => DB::transaction(function () use ($ticket) {
                        // Conditional update: a customer reply that reopened the ticket
                        // in the meantime wins, and the ticket is left alone.
                        $updated = Ticket::whereKey($ticket->id)
                            ->where('status', TicketStatus::Resolved)
                            ->update(['status' => TicketStatus::Closed, 'closed_at' => now()]);

                        if ($updated === 1) {
                            $ticket->activities()->create([
                                'type' => ActivityType::StatusChanged,
                                'data' => ['from' => 'resolved', 'to' => 'closed', 'automatic' => true],
                            ]);
                        }

                        return $updated;
                    }));
                }
            });

        $this->info("Closed {$closed} ticket(s).");

        return self::SUCCESS;
    }
}
