<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts tenant-owned models to the current organization.
 *
 * Fails closed: with no organization in context, queries return nothing.
 * Code that legitimately works across tenants (the SLA scheduler) must opt out
 * explicitly with withoutGlobalScope(OrganizationScope::class).
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = app(CurrentOrganization::class)->id();

        if ($organizationId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('organization_id'), $organizationId);
    }
}
