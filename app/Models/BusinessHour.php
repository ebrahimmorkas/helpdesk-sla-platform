<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Working hours for one ISO weekday (1 = Monday), in the organization's timezone. */
#[Fillable(['organization_id', 'weekday', 'opens_at', 'closes_at'])]
class BusinessHour extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }
}
