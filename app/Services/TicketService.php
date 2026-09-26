<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Exceptions\TicketClosedException;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketRepliedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

class TicketService
{
    public function __construct(
        private readonly SlaClock $clock,
        private readonly AttachmentStore $attachments,
    ) {}

    /**
     * @param  array{subject: string, description: string, priority?: string|null, requester_id?: int|null}  $data
     */
    public function open(array $data, User $actor): Ticket
    {
        return DB::transaction(function () use ($data, $actor) {
            $ticket = new Ticket([
                'organization_id' => $actor->organization_id,
                'number' => $this->nextNumber($actor->organization_id),
                'requester_id' => $actor->isStaff() ? $data['requester_id'] : $actor->id,
                'subject' => $data['subject'],
                'description' => $data['description'],
                'status' => TicketStatus::Open,
                // Customers cannot choose their own priority.
                'priority' => $actor->isStaff() && isset($data['priority'])
                    ? TicketPriority::from($data['priority'])
                    : TicketPriority::Normal,
            ]);
            $ticket->created_at = now();
            $this->clock->schedule($ticket);
            $ticket->save();

            $this->record($ticket, $actor, ActivityType::Created, ['priority' => $ticket->priority->value]);

            return $ticket;
        });
    }

    /**
     * Add a reply or internal note and apply the status rules:
     *  - an agent's public reply counts as the first response and, by default,
     *    puts the ticket in "pending" (waiting on the customer);
     *  - a customer's reply to a pending or resolved ticket reopens it;
     *  - internal notes never change status or SLA.
     *
     * @param  list<UploadedFile>  $files
     */
    public function reply(
        Ticket $ticket,
        User $author,
        string $body,
        bool $internal = false,
        ?TicketStatus $status = null,
        array $files = [],
    ): TicketMessage {
        $written = [];

        try {
            return DB::transaction(function () use ($ticket, $author, $body, $internal, $status, $files, &$written) {
                return $this->addMessage($ticket, $author, $body, $internal, $status, $files, $written);
            });
        } catch (Throwable $e) {
            $this->attachments->delete($written);

            throw $e;
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $written
     */
    private function addMessage(Ticket $ticket, User $author, string $body, bool $internal, ?TicketStatus $status, array $files, array &$written): TicketMessage
    {
        $ticket = $this->lock($ticket);

        $message = $ticket->messages()->create([
            'author_id' => $author->id,
            'body' => $body,
            'is_internal' => $author->isStaff() && $internal,
        ]);

        $this->attachments->attach($message, $files, $written);

        if (! $message->is_internal) {
            if ($author->isStaff()) {
                $ticket->first_responded_at ??= now();
                $this->transition($ticket, $status ?? TicketStatus::Pending, $author);
            } elseif (in_array($ticket->status, [TicketStatus::Pending, TicketStatus::Resolved], true)) {
                $this->transition($ticket, TicketStatus::Open, $author);
            }

            $ticket->save();
            $this->notifyOtherParty($ticket, $message, $author);
        }

        return $message;
    }

    /**
     * Staff changes to status, priority and assignee.
     *
     * @param  array{status?: string, priority?: string, assignee_id?: int|null}  $changes
     */
    public function update(Ticket $ticket, array $changes, User $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $changes, $actor) {
            $ticket = $this->lock($ticket);

            if (isset($changes['status'])) {
                $this->transition($ticket, TicketStatus::from($changes['status']), $actor);
            }

            if (isset($changes['priority']) && $changes['priority'] !== $ticket->priority->value) {
                $from = $ticket->priority;
                $ticket->priority = TicketPriority::from($changes['priority']);
                $this->clock->schedule($ticket);
                $this->record($ticket, $actor, ActivityType::PriorityChanged, ['from' => $from->value, 'to' => $ticket->priority->value]);
            }

            if (array_key_exists('assignee_id', $changes) && $changes['assignee_id'] !== $ticket->assignee_id) {
                $ticket->assignee_id = $changes['assignee_id'];
                $this->record($ticket, $actor, ActivityType::Assigned, ['assignee_id' => $ticket->assignee_id]);

                if ($ticket->assignee_id !== null && $ticket->assignee_id !== $actor->id) {
                    $this->notifyAfterCommit($ticket->assignee, new TicketAssignedNotification($ticket));
                }
            }

            $ticket->save();

            return $ticket;
        });
    }

    private function transition(Ticket $ticket, TicketStatus $to, User $actor): void
    {
        $from = $ticket->status;

        if ($from === $to) {
            return;
        }

        $now = now();

        if ($from === TicketStatus::Pending) {
            $this->clock->resume($ticket, $now);
        }

        match ($to) {
            TicketStatus::Pending => $this->clock->pause($ticket, $now),
            TicketStatus::Resolved => $ticket->resolved_at = $now,
            TicketStatus::Closed => $ticket->closed_at = $now,
            TicketStatus::Open => $ticket->resolved_at = null,
        };

        $ticket->status = $to;
        $this->record($ticket, $actor, ActivityType::StatusChanged, ['from' => $from->value, 'to' => $to->value]);
    }

    /**
     * Re-read the ticket under a row lock so concurrent replies and updates apply
     * their status and SLA changes one after the other. Closed tickets are final.
     */
    private function lock(Ticket $ticket): Ticket
    {
        $locked = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();

        if ($locked->status === TicketStatus::Closed) {
            throw new TicketClosedException;
        }

        return $locked;
    }

    /**
     * Ticket numbers are sequential per organization. The organization row is
     * locked while the number is taken, so concurrent tickets get distinct
     * numbers; the unique (organization_id, number) index backs this up.
     */
    private function nextNumber(int $organizationId): int
    {
        $organization = Organization::whereKey($organizationId)->lockForUpdate()->firstOrFail();
        $organization->increment('ticket_sequence');

        return $organization->ticket_sequence;
    }

    private function record(Ticket $ticket, ?User $actor, ActivityType $type, array $data = []): void
    {
        $ticket->activities()->create(['actor_id' => $actor?->id, 'type' => $type, 'data' => $data]);
    }

    private function notifyOtherParty(Ticket $ticket, TicketMessage $message, User $author): void
    {
        $recipient = $author->isStaff() ? $ticket->requester : $ticket->assignee;

        if ($recipient !== null && $recipient->isNot($author) && $recipient->is_active) {
            $this->notifyAfterCommit($recipient, new TicketRepliedNotification($ticket, $message));
        }
    }

    /**
     * Queue the notification only once the transaction has committed, so a
     * rollback sends nothing. This uses DB::afterCommit rather than the
     * notification's own afterCommit(): with the latter, the Redis queue defers
     * its push to commit time, outside the failover queue's error handling, so a
     * Redis outage failed the request after the data had already been saved.
     */
    private function notifyAfterCommit(User $recipient, Notification $notification): void
    {
        DB::afterCommit(fn () => $recipient->notify($notification));
    }
}
