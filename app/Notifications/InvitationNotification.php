<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Encrypted on the queue because the payload contains the plain invitation token.
 */
class InvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600];

    /**
     * The organization name is captured now: the queued job has no tenant
     * context, and the organization relation is not needed later.
     */
    public readonly string $organizationName;

    public function __construct(Invitation $invitation, public readonly string $token)
    {
        $this->organizationName = $invitation->organization()->value('name');
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You have been invited to {$this->organizationName}")
            ->line("You have been invited to join {$this->organizationName} on the helpdesk.")
            ->line('Accept the invitation by sending this token to POST /api/v1/invitations/accept with a password:')
            ->line($this->token)
            ->line('The invitation expires in 7 days.');
    }
}
