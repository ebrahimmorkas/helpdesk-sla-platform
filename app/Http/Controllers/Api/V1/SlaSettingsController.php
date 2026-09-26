<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Models\BusinessHour;
use App\Models\Organization;
use App\Models\SlaPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SLA configuration of the current organization. Readable by staff, writable
 * by admins. Changes apply to tickets created or re-prioritised afterwards;
 * due dates of existing tickets are not recalculated.
 */
class SlaSettingsController extends Controller
{
    public function policies(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        return response()->json(['data' => SlaPolicy::orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->get(['priority', 'first_response_minutes', 'resolution_minutes'])]);
    }

    public function updatePolicy(Request $request, TicketPriority $priority): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'first_response_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
            'resolution_minutes' => ['required', 'integer', 'gte:first_response_minutes', 'max:525600'],
        ]);

        $policy = SlaPolicy::updateOrCreate(['priority' => $priority], $data);

        return response()->json(['data' => $policy->only(['priority', 'first_response_minutes', 'resolution_minutes'])]);
    }

    public function businessHours(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        return response()->json(['data' => $this->presentHours($request->user()->organization)]);
    }

    /**
     * Replaces the whole weekly schedule. An empty "days" list means 24/7.
     */
    public function updateBusinessHours(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'timezone' => ['required', 'timezone:all'],
            'days' => ['present', 'array', 'max:7'],
            'days.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'days.*.opens_at' => ['required', 'date_format:H:i'],
            'days.*.closes_at' => ['required', 'date_format:H:i', 'after:days.*.opens_at'],
        ]);

        $organization = $request->user()->organization;

        DB::transaction(function () use ($organization, $data) {
            $organization->update(['timezone' => $data['timezone']]);
            BusinessHour::query()->delete();
            foreach ($data['days'] as $day) {
                BusinessHour::create($day);
            }
        });

        return response()->json(['data' => $this->presentHours($organization->fresh())]);
    }

    private function presentHours(Organization $organization): array
    {
        return [
            'timezone' => $organization->timezone,
            'days' => $organization->businessHours->map(fn (BusinessHour $day) => [
                'weekday' => $day->weekday,
                'opens_at' => substr($day->opens_at, 0, 5),
                'closes_at' => substr($day->closes_at, 0, 5),
            ]),
        ];
    }
}
