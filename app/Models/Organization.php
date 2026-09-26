<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A tenant: one company using the helpdesk. */
#[Fillable(['name', 'slug', 'timezone'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ticket_sequence' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function businessHours(): HasMany
    {
        return $this->hasMany(BusinessHour::class)->withoutGlobalScopes()->orderBy('weekday');
    }

    public function slaPolicies(): HasMany
    {
        return $this->hasMany(SlaPolicy::class)->withoutGlobalScopes();
    }
}
