<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Reports\SlaComplianceReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function slaCompliance(Request $request, SlaComplianceReport $report): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);

        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => 'The date range may not exceed 366 days.']);
        }

        return response()->json(['data' => $report->generate($request->user()->organization_id, $from, $to)]);
    }

    /**
     * Open work per agent, straight from indexed columns (not cached).
     */
    public function workload(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $counts = Ticket::query()
            ->whereIn('status', [TicketStatus::Open, TicketStatus::Pending])
            ->groupBy('assignee_id')
            ->select('assignee_id')
            ->selectRaw("SUM(status = 'open') as open")
            ->selectRaw("SUM(status = 'pending') as pending")
            ->selectRaw('SUM(status = ? AND resolution_due_at < ? AND sla_paused_at IS NULL) as overdue', [TicketStatus::Open->value, now()])
            ->toBase()
            ->get()
            ->keyBy('assignee_id');

        $agents = User::inCurrentOrganization()->whereIn('role', ['admin', 'agent'])->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $rows = $agents->map(fn (User $agent) => [
            'agent' => $agent->only(['id', 'name']),
            'open' => (int) ($counts->get($agent->id)->open ?? 0),
            'pending' => (int) ($counts->get($agent->id)->pending ?? 0),
            'overdue' => (int) ($counts->get($agent->id)->overdue ?? 0),
        ]);

        $unassigned = $counts->get('');

        return response()->json(['data' => [
            'agents' => $rows,
            'unassigned' => [
                'open' => (int) ($unassigned->open ?? 0),
                'pending' => (int) ($unassigned->pending ?? 0),
                'overdue' => (int) ($unassigned->overdue ?? 0),
            ],
        ]]);
    }
}
