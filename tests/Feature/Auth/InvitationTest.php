<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Tenancy\OrganizationScope;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function inviteAndCaptureToken(array $payload): string
    {
        $token = null;
        Notification::fake();

        $this->postJson('/api/v1/invitations', $payload)->assertCreated()->assertJsonMissingPath('data.token');

        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function ($notification, $channels, $notifiable) use ($payload, &$token) {
            $token = $notification->token;

            return $notifiable->routes['mail'] === $payload['email'];
        });

        return $token;
    }

    public function test_admin_invites_an_agent_who_accepts_and_joins_the_organization(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsMember($organization, Role::Admin);

        $token = $this->inviteAndCaptureToken(['email' => 'new.agent@acme.test', 'name' => 'New Agent', 'role' => 'agent']);

        $this->assertDatabaseMissing('invitations', ['token_hash' => $token]);
        $this->assertDatabaseHas('invitations', ['token_hash' => hash('sha256', $token)]);

        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'password' => 'correct-horse-42'])
            ->assertCreated()
            ->assertJsonPath('user.role', 'agent')
            ->assertJsonPath('user.organization.id', $organization->id);

        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'password' => 'correct-horse-42'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    public function test_expired_invitations_cannot_be_accepted(): void
    {
        $this->actingAsMember(Organization::factory()->create(), Role::Admin);
        $token = $this->inviteAndCaptureToken(['email' => 'late@acme.test', 'name' => 'Late', 'role' => 'customer']);

        $this->travel(8)->days();

        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'password' => 'correct-horse-42'])
            ->assertUnprocessable();
        $this->assertFalse(User::where('email', 'late@acme.test')->exists());
    }

    public function test_agents_can_only_invite_customers(): void
    {
        $this->actingAsMember(Organization::factory()->create(), Role::Agent);
        Notification::fake();

        $this->postJson('/api/v1/invitations', ['email' => 'x@acme.test', 'name' => 'X', 'role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->postJson('/api/v1/invitations', ['email' => 'c@acme.test', 'name' => 'C', 'role' => 'customer'])
            ->assertCreated();
    }

    public function test_customers_cannot_invite(): void
    {
        $this->actingAsMember(Organization::factory()->create(), Role::Customer);

        $this->postJson('/api/v1/invitations', ['email' => 'c@acme.test', 'name' => 'C', 'role' => 'customer'])
            ->assertForbidden();
    }

    public function test_admins_cannot_see_or_revoke_invitations_of_other_organizations(): void
    {
        [$acme, $globex] = Organization::factory()->count(2)->create();
        $foreign = Invitation::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $globex->id, 'email' => 'x@globex.test', 'name' => 'X', 'role' => Role::Agent,
            'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay(),
        ]);
        $this->actingAsMember($acme, Role::Admin);

        $this->getJson('/api/v1/invitations')->assertOk()->assertJsonPath('total', 0);
        $this->deleteJson("/api/v1/invitations/{$foreign->id}")->assertNotFound();
    }

    public function test_invitation_mail_is_encrypted_on_the_queue(): void
    {
        $this->assertContains(ShouldBeEncrypted::class, class_implements(InvitationNotification::class));
    }
}
