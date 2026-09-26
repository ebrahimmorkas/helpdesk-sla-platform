<?php

namespace App\Console\Commands;

use App\Enums\ActivityType;
use App\Enums\SlaMetric;
use App\Enums\TicketStatus;
use App\Events\SlaBreached;
use App\Models\Ticket;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\OrganizationScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Runs every minute across all tenants (so it opts out of the organization
 * scope explicitly) and records each missed SLA target exactly once.
 *
 * Idempotency comes from the unique (ticket_id, metric) key on sla_breaches:
 * insertOrIgnore reports whether this run created the row, and only then is
 * the breach announced. Overlapping or repeated runs cannot double-notify.
 */
#[Signature('sla:detect-breaches')]
#[Description('Record and announce tickets that missed their first response or resolution target')]
class DetectSlaBreaches extends Command
{
    public function handle(CurrentOrganization $context): int
    {
        $now = now();
        $count = 0;

        $firstResponse = $this->candidates(SlaMetric::FirstResponse)
            ->whereNull('first_responded_at')
            ->where('first_response_due_at', '<=', $now)
            ->whereIn('status', [TicketStatus::Open, TicketStatus::Pending]);

        // Paused tickets (waiting on the customer) cannot breach resolution.
        $resolution = $this->candidates(SlaMetric::Resolution)
            ->whereNull('resolved_at')
            ->whereNull('sla_paused_at')
            ->where('resolution_due_at', '<=', $now)
            ->where('status', TicketStatus::Open);

        foreach ([[SlaMetric::FirstResponse, $firstResponse, 'first_response_due_at'], [SlaMetric::Resolution, $resolution, 'resolution_due_at']] as [$metric, $query, $dueColumn]) {
            $query->chunkById(500, function ($tickets) use ($metric, $dueColumn, $now, $context, &$count) {
                foreach ($tickets as $ticket) {
                    $inserted = DB::table('sla_breaches')->insertOrIgnore([
                        'organization_id' => $ticket->organization_id,
                        'ticket_id' => $ticket->id,
                        'metric' => $metric->value,
                        'due_at' => $ticket->{$dueColumn},
                        'breached_at' => $now,
                    ]);

                    if ($inserted === 0) {
                        continue;
                    }

                    $context->run($ticket->organization_id, function () use ($ticket, $metric) {
                        $ticket->activities()->create(['type' => ActivityType::SlaBreached, 'data' => ['metric' => $metric->value]]);
                    });

                    SlaBreached::dispatch($ticket->id, $ticket->organization_id, $metric);
                    $count++;
                }
            });
        }

        $this->info("Recorded {$count} new SLA breach(es).");

        return self::SUCCESS;
    }

    private function candidates(SlaMetric $metric): Builder
    {
        return Ticket::withoutGlobalScope(OrganizationScope::class)
            ->whereDoesntHave('breaches', fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)->where('metric', $metric))
            ->select(['id', 'organization_id', 'first_response_due_at', 'resolution_due_at']);
    }
}
