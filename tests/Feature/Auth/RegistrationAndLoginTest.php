<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationAndLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_an_organization_with_an_admin_and_default_sla_setup(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'organization_name' => 'Acme Robotics',
            'timezone' => 'America/New_York',
            'name' => 'Ada Admin',
            'email' => 'ada@acme.test',
            'password' => 'correct-horse-42',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.organization.timezone', 'America/New_York')
            ->assertJsonStructure(['token', 'expires_at']);

        $organization = Organization::where('name', 'Acme Robotics')->sole();
        $this->assertStringStartsWith('acme-robotics-', $organization->slug);
        $this->assertSame(4, $organization->slaPolicies()->count());
        $this->assertSame([1, 2, 3, 4, 5], $organization->businessHours()->pluck('weekday')->all());
    }

    public function test_registration_validation(): void
    {
        User::factory()->create(['email' => 'taken@acme.test']);

        $this->postJson('/api/v1/register', [
            'organization_name' => '',
            'timezone' => 'Mars/Olympus',
            'email' => 'taken@acme.test',
            'password' => 'short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_name', 'timezone', 'name', 'email', 'password']);
    }

    public function test_login_logout_and_profile(): void
    {
        User::factory()->create(['email' => 'agent@acme.test']);

        $token = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'agent@acme.test', 'password' => 'password', 'device_name' => 'laptop',
        ])->assertCreated()->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'agent@acme.test');
        $this->withToken($token)->deleteJson('/api/v1/auth/tokens/current')->assertNoContent();
    }

    public function test_wrong_password_and_inactive_users_get_the_same_error(): void
    {
        User::factory()->create(['email' => 'agent@acme.test']);
        User::factory()->inactive()->create(['email' => 'gone@acme.test']);

        foreach ([['agent@acme.test', 'wrong'], ['gone@acme.test', 'password'], ['nobody@acme.test', 'password']] as [$email, $password]) {
            $this->postJson('/api/v1/auth/tokens', ['email' => $email, 'password' => $password, 'device_name' => 'x'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['email' => __('auth.failed')]);
        }
    }

    public function test_deactivated_users_are_rejected_on_every_request(): void
    {
        Sanctum::actingAs(User::factory()->inactive()->create());

        $this->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_admin_manages_users_but_cannot_lock_themselves_out(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->actingAsMember($organization, Role::Admin);
        $agent = $this->member($organization);
        $agent->createToken('laptop');

        $this->patchJson("/api/v1/users/{$agent->id}", ['is_active' => false])->assertOk();
        $this->assertSame(0, $agent->tokens()->count());

        $this->patchJson("/api/v1/users/{$admin->id}", ['role' => 'agent'])->assertUnprocessable();
    }

    public function test_users_of_other_organizations_are_invisible(): void
    {
        [$acme, $globex] = Organization::factory()->count(2)->create();
        $this->actingAsMember($acme, Role::Admin);
        $outsider = $this->member($globex);

        $this->getJson('/api/v1/users')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/users/{$outsider->id}")->assertNotFound();
        $this->patchJson("/api/v1/users/{$outsider->id}", ['is_active' => false])->assertNotFound();

        $this->assertTrue($outsider->fresh()->is_active);
    }

    public function test_customers_cannot_list_users(): void
    {
        $this->actingAsMember(Organization::factory()->create(), Role::Customer);

        $this->getJson('/api/v1/users')->assertForbidden();
    }
}
