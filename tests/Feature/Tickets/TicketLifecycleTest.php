<?php

namespace Tests\Feature\Tickets;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Services\OrganizationRegistrar;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo('2026-01-05 10:00:00'); // Monday, inside business hours
        $this->organization = app(OrganizationRegistrar::class)->register([
            'organization_name' => 'Acme', 'timezone' => 'Europe/London', 'name' => 'Ada',
            'email' => 'ada@acme.test', 'password' => 'correct-horse-42',
        ])->organization;
    }

    private function as(User $user): User
    {
        Sanctum::actingAs($user);
        app(CurrentOrganization::class)->set($user->organization_id);

        return $user;
    }

    private function openAs(User $customer, string $subject = 'Printer on fire'): int
    {
        $this->as($customer);

        return $this->postJson('/api/v1/tickets', ['subject' => $subject, 'description' => 'Please help'])
            ->assertCreated()
            ->json('data.number');
    }

    public function test_customer_opens_tickets_with_sequential_numbers_and_sla_due_dates(): void
    {
        $customer = $this->member($this->organization, Role::Customer);

        $this->assertSame(1, $this->openAs($customer));
        $this->assertSame(2, $this->openAs($customer));

        $ticket = Ticket::where('number', 2)->sole();
        $this->assertSame('normal', $ticket->priority->value);
        // Normal: 4h first response, 24 business hours resolution, from Monday 10:00.
        $this->assertSame('2026-01-05 14:00:00', $ticket->first_response_due_at->toDateTimeString());
        $this->assertSame('2026-01-08 10:00:00', $ticket->resolution_due_at->toDateTimeString());
        $this->assertSame(['created'], $ticket->activities()->pluck('type')->map->value->all());
    }

    public function test_customers_cannot_choose_priority_or_requester(): void
    {
        $this->as($this->member($this->organization, Role::Customer));

        $this->postJson('/api/v1/tickets', ['subject' => 'x', 'description' => 'y', 'priority' => 'urgent', 'requester_id' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['priority', 'requester_id']);
    }

    public function test_agents_open_tickets_only_for_customers_of_their_organization(): void
    {
        $foreignCustomer = $this->member(Organization::factory()->create(), Role::Customer);
        $customer = $this->member($this->organization, Role::Customer);
        $this->as($this->member($this->organization));

        $this->postJson('/api/v1/tickets', ['subject' => 'x', 'description' => 'y', 'requester_id' => $foreignCustomer->id])
            ->assertUnprocessable()->assertJsonValidationErrors('requester_id');

        $this->postJson('/api/v1/tickets', ['subject' => 'x', 'description' => 'y', 'requester_id' => $customer->id, 'priority' => 'urgent'])
            ->assertCreated()->assertJsonPath('data.priority', 'urgent');
    }

    public function test_agent_reply_counts_as_first_response_and_waits_on_the_customer(): void
    {
        $customer = $this->member($this->organization, Role::Customer);
        $number = $this->openAs($customer);
        $this->as($this->member($this->organization));

        $this->travel(30)->minutes();
        $this->postJson("/api/v1/tickets/{$number}/messages", ['body' => 'Have you tried turning it off?'])->assertCreated();

        $ticket = Ticket::where('number', $number)->sole();
        $this->assertSame('pending', $ticket->status->value);
        $this->assertSame('2026-01-05 10:30:00', $ticket->first_responded_at->toDateTimeString());
        $this->assertNotNull($ticket->sla_paused_at);
        Notification::assertSentTo($customer, TicketRepliedNotification::class);
    }

    public function test_customer_reply_reopens_the_ticket_and_extends_the_resolution_target(): void
    {
        $customer = $this->member($this->organization, Role::Customer);
        $number = $this->openAs($customer);
        $this->as($this->member($this->organization));
        $this->postJson("/api/v1/tickets/{$number}/messages", ['body' => 'Which model?'])->assertCreated();
        $dueBefore = Ticket::where('number', $number)->value('resolution_due_at');

        $this->travel(90)->minutes();
        $this->as($customer);
        $this->postJson("/api/v1/tickets/{$number}/messages", ['body' => 'Model X200'])->assertCreated();

        $ticket = Ticket::where('number', $number)->sole();
        $this->assertSame('open', $ticket->status->value);
        $this->assertSame(90, $ticket->sla_paused_minutes);
        $this->assertSame(90, (int) abs($ticket->resolution_due_at->diffInMinutes($dueBefore)));
    }

    public function test_internal_notes_are_hidden_from_customers(): void
    {
        $customer = $this->member($this->organization, Role::Customer);
        $number = $this->openAs($customer);

        $this->as($this->member($this->organization));
        $this->postJson("/api/v1/tickets/{$number}/messages", ['body' => 'Customer is on the legacy plan', 'is_internal' => true])
            ->assertCreated();
        $this->getJson("/api/v1/tickets/{$number}")->assertJsonCount(1, 'data.messages');
        $this->assertSame(TicketStatus::Open, Ticket::where('number', $number)->value('status'));

        $this->as($customer);
        $this->getJson("/api/v1/tickets/{$number}")
            ->assertOk()
            ->assertJsonCount(0, 'data.messages')
            ->assertJsonMissingPath('data.sla');
        $this->postJson("/api/v1/tickets/{$number}/messages", ['body' => 'x', 'is_internal' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('is_internal');
    }

    public function test_assigning_reprioritising_and_closing(): void
    {
        $number = $this->openAs($this->member($this->organization, Role::Customer));
        $agent = $this->member($this->organization);
        $this->as($this->member($this->organization, Role::Admin));

        $this->patchJson("/api/v1/tickets/{$number}", ['assignee_id' => $agent->id, 'priority' => 'urgent'])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $agent->id)
            ->assertJsonPath('data.sla.first_response_due_at', '2026-01-05T10:15:00+00:00');
        Notification::assertSentTo($agent, TicketAssignedNotification::class);

        $this->patchJson("/api/v1/tickets/{$number}", ['status' => 'closed'])->assertOk();
        $this->patchJson("/api/v1/tickets/{$number}", ['status' => 'open'])->assertConflict();
        $this->postJson("/api/v1/tickets/{$number}/messages", ['body' => 'hello?'])->assertConflict();

        $this->getJson("/api/v1/tickets/{$number}/activity")
            ->assertOk()
            ->assertJsonPath('data.*.type', ['created', 'priority_changed', 'assigned', 'status_changed']);
    }

    public function test_only_active_staff_of_the_organization_can_be_assigned(): void
    {
        $number = $this->openAs($this->member($this->organization, Role::Customer));
        $this->as($this->member($this->organization));

        foreach ([
            $this->member($this->organization, Role::Customer),
            $this->member($this->organization, Role::Agent, ['is_active' => false]),
            $this->member(Organization::factory()->create()),
        ] as $invalid) {
            $this->patchJson("/api/v1/tickets/{$number}", ['assignee_id' => $invalid->id])
                ->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        }
    }

    public function test_customers_see_only_their_own_tickets(): void
    {
        [$alice, $bob] = [$this->member($this->organization, Role::Customer), $this->member($this->organization, Role::Customer)];
        $alicesTicket = $this->openAs($alice);
        $this->openAs($bob);

        $this->as($bob);
        $this->getJson('/api/v1/tickets')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/tickets/{$alicesTicket}")->assertForbidden();
        $this->postJson("/api/v1/tickets/{$alicesTicket}/messages", ['body' => 'x'])->assertForbidden();
        $this->patchJson("/api/v1/tickets/{$alicesTicket}", ['status' => 'closed'])->assertForbidden();
        $this->getJson("/api/v1/tickets/{$alicesTicket}/activity")->assertForbidden();
    }

    public function test_tickets_of_another_organization_do_not_exist_for_this_tenant(): void
    {
        $number = $this->openAs($this->member($this->organization, Role::Customer));
        $other = app(OrganizationRegistrar::class)->register([
            'organization_name' => 'Globex', 'timezone' => 'UTC', 'name' => 'Hank',
            'email' => 'hank@globex.test', 'password' => 'correct-horse-42',
        ]);

        $this->as($other); // an admin of another tenant, same ticket number space
        $this->getJson("/api/v1/tickets/{$number}")->assertNotFound();
        $this->patchJson("/api/v1/tickets/{$number}", ['status' => 'closed'])->assertNotFound();
        $this->postJson("/api/v1/tickets/{$number}/messages", ['body' => 'x'])->assertNotFound();
        $this->getJson('/api/v1/tickets')->assertJsonPath('meta.total', 0);
    }

    public function test_staff_filters_by_status_and_assignee(): void
    {
        $customer = $this->member($this->organization, Role::Customer);
        foreach (range(1, 3) as $i) {
            $this->openAs($customer);
        }
        $agent = $this->as($this->member($this->organization));
        $this->patchJson('/api/v1/tickets/1', ['assignee_id' => $agent->id])->assertOk();
        $this->patchJson('/api/v1/tickets/2', ['status' => 'resolved'])->assertOk();

        $this->getJson('/api/v1/tickets?assignee=me')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/tickets?assignee=none&status[]=open')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/tickets?status[]=open&status[]=resolved&sort=number')
            ->assertJsonPath('data.0.number', 1)
            ->assertJsonPath('meta.total', 3);
    }
}
