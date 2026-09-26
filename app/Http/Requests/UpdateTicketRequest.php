<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('ticket'));
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(TicketStatus::class)],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
            // Only active staff of the same organization can be assigned.
            'assignee_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')
                ->where('organization_id', $this->user()->organization_id)
                ->whereIn('role', [Role::Admin->value, Role::Agent->value])
                ->where('is_active', true)],
        ];
    }
}
