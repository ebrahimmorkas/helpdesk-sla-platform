<?php

namespace App\Models;

use App\Enums\SlaMetric;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'ticket_id', 'metric', 'due_at', 'breached_at'])]
class SlaBreach extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'metric' => SlaMetric::class,
            'due_at' => 'datetime',
            'breached_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
