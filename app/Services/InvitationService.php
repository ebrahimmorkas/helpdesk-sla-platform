<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Tenancy\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationService
{
    public const EXPIRES_AFTER_DAYS = 7;

    /**
     * Only the SHA-256 hash of the token is stored. The plain token exists only
     * in the email, so a database leak does not expose usable invitations.
     */
    public function invite(User $inviter, string $email, string $name, Role $role): Invitation
    {
        $token = Str::random(64);

        $invitation = Invitation::create([
            'invited_by' => $inviter->id,
            'email' => $email,
            'name' => $name,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
        ]);

        Notification::route('mail', $email)->notify(new InvitationNotification($invitation, $token));

        return $invitation;
    }

    /**
     * Accepting happens before the user belongs to any tenant, so the lookup
     * deliberately bypasses the organization scope; the hashed token is the
     * only thing that identifies the invitation.
     */
    public function accept(string $token, string $password): User
    {
        return DB::transaction(function () use ($token, $password) {
            $invitation = Invitation::withoutGlobalScope(OrganizationScope::class)
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! $invitation->isUsable()) {
                throw ValidationException::withMessages(['token' => 'This invitation is invalid or has expired.']);
            }

            if (User::where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['token' => 'An account with this email address already exists.']);
            }

            $user = User::create([
                'organization_id' => $invitation->organization_id,
                'name' => $invitation->name,
                'email' => $invitation->email,
                'password' => $password,
                'role' => $invitation->role,
            ]);

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });
    }
}
