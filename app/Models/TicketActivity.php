<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only timeline entry for a ticket. */
#[Fillable(['organization_id', 'ticket_id', 'actor_id', 'type', 'data'])]
class TicketActivity extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'data' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
