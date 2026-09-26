<?php

namespace Tests;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function member(Organization $organization, Role $role = Role::Agent, array $attributes = []): User
    {
        return User::factory()->for($organization)->create(['role' => $role, ...$attributes]);
    }

    /**
     * Authenticate as a member of the organization. The tenant context is also
     * set so that assertions using Eloquent see the same organization.
     */
    protected function actingAsMember(Organization $organization, Role $role = Role::Agent): User
    {
        $user = $this->member($organization, $role);
        Sanctum::actingAs($user);
        app(CurrentOrganization::class)->set($organization);

        return $user;
    }
}
