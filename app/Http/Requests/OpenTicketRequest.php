<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Staff open tickets on behalf of a customer of their own organization and
     * may set the priority; customers always open tickets for themselves.
     */
    public function rules(): array
    {
        $staff = $this->user()->isStaff();

        return [
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'requester_id' => $staff
                ? ['required', 'integer', Rule::exists('users', 'id')->where('organization_id', $this->user()->organization_id)->where('role', Role::Customer->value)]
                : ['prohibited'],
            'priority' => $staff ? ['nullable', Rule::enum(TicketPriority::class)] : ['prohibited'],
        ];
    }
}
