<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Response and resolution targets, in business minutes, for one priority. */
#[Fillable(['organization_id', 'priority', 'first_response_minutes', 'resolution_minutes'])]
class SlaPolicy extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'first_response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
        ];
    }
}
