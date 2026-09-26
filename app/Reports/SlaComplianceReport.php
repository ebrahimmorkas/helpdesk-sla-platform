<?php

namespace App\Reports;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * SLA compliance per priority for tickets created in a date range.
 *
 * A target counts as "met" when it was achieved before its due date, and as
 * "breached" when it was achieved late or is still outstanding past its due
 * date. Tickets still inside their target are neither.
 *
 * Results are cached for CACHE_SECONDS. The key always contains the
 * organization id: a cache shared between tenants must never serve one
 * tenant's figures to another.
 */
class SlaComplianceReport
{
    public const CACHE_SECONDS = 600;

    /**
     * @return array{from: string, to: string, generated_at: string, priorities: list<array<string, mixed>>}
     */
    public function generate(int $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = sprintf('reports:sla-compliance:org:%d:%s:%s', $organizationId, $from->toDateString(), $to->toDateString());

        return Cache::remember($key, self::CACHE_SECONDS, fn () => $this->compute($from, $to));
    }

    private function compute(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Ticket::query()
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->groupBy('priority')
            ->select('priority')
            ->selectRaw('COUNT(*) as tickets')
            ->selectRaw('SUM(first_responded_at IS NOT NULL AND first_responded_at <= first_response_due_at) as first_response_met')
            ->selectRaw('SUM((first_responded_at > first_response_due_at) OR (first_responded_at IS NULL AND first_response_due_at < ?)) as first_response_breached', [now()])
            ->selectRaw('SUM(resolved_at IS NOT NULL AND resolved_at <= resolution_due_at) as resolution_met')
            ->selectRaw('SUM((resolved_at > resolution_due_at) OR (resolved_at IS NULL AND closed_at IS NULL AND sla_paused_at IS NULL AND resolution_due_at < ?)) as resolution_breached', [now()])
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, first_responded_at)) as avg_first_response_minutes')
            ->toBase()
            ->get()
            ->keyBy('priority');

        $priorities = collect(TicketPriority::cases())->map(function (TicketPriority $priority) use ($rows) {
            $row = $rows->get($priority->value);

            $firstMet = (int) ($row->first_response_met ?? 0);
            $firstBreached = (int) ($row->first_response_breached ?? 0);
            $resolutionMet = (int) ($row->resolution_met ?? 0);
            $resolutionBreached = (int) ($row->resolution_breached ?? 0);

            return [
                'priority' => $priority->value,
                'tickets' => (int) ($row->tickets ?? 0),
                'first_response' => [
                    'met' => $firstMet,
                    'breached' => $firstBreached,
                    'compliance_percent' => $this->percent($firstMet, $firstBreached),
                    'average_minutes' => $row?->avg_first_response_minutes !== null ? (int) round($row->avg_first_response_minutes) : null,
                ],
                'resolution' => [
                    'met' => $resolutionMet,
                    'breached' => $resolutionBreached,
                    'compliance_percent' => $this->percent($resolutionMet, $resolutionBreached),
                ],
            ];
        })->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'priorities' => $priorities,
        ];
    }

    private function percent(int $met, int $breached): ?float
    {
        $total = $met + $breached;

        return $total === 0 ? null : round($met / $total * 100, 1);
    }
}
