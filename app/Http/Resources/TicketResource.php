<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()->isStaff();

        return [
            'number' => $this->number,
            'subject' => $this->subject,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'requester' => $this->whenLoaded('requester', fn () => $this->requester->only(['id', 'name', 'email'])),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee?->only(['id', 'name'])),
            // SLA targets are internal to the support team.
            'sla' => $this->when($staff, fn () => [
                'first_response_due_at' => $this->first_response_due_at?->toIso8601String(),
                'first_responded_at' => $this->first_responded_at?->toIso8601String(),
                'resolution_due_at' => $this->resolution_due_at?->toIso8601String(),
                'paused' => $this->sla_paused_at !== null,
                'paused_minutes' => $this->sla_paused_minutes,
            ]),
            'messages' => TicketMessageResource::collection($this->whenLoaded('messages')),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
