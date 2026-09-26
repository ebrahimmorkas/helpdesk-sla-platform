<?php

namespace Tests\Feature\Console;

use App\Enums\Role;
use App\Enums\SlaMetric;
use App\Enums\TicketStatus;
use App\Events\SlaBreached;
use App\Listeners\EscalateSlaBreach;
use App\Models\Organization;
use App\Models\SlaBreach;
use App\Models\Ticket;
use App\Notifications\SlaBreachedNotification;
use App\Services\OrganizationRegistrar;
use App\Services\TicketService;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\OrganizationScope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SlaBreachDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo('2026-01-05 09:00:00'); // Monday, business hours start
    }

    private function organization(string $name = 'Acme'): Organization
    {
        return app(OrganizationRegistrar::class)->register([
            'organization_name' => $name, 'timezone' => 'Europe/London', 'name' => 'Admin',
            'email' => fake()->unique()->safeEmail(), 'password' => 'correct-horse-42',
        ])->organization;
    }

    private function urgentTicket(Organization $organization): Ticket
    {
        // Urgent: 15 minutes first response, 4 hours resolution.
        $agent = $this->member($organization);

        return app(CurrentOrganization::class)->run($organization, fn () => app(TicketService::class)->open([
            'subject' => 'Checkout is down', 'description' => '500 errors',
            'requester_id' => $this->member($organization, Role::Customer)->id, 'priority' => 'urgent',
        ], $agent));
    }

    private function breaches(): Collection
    {
        return SlaBreach::withoutGlobalScope(OrganizationScope::class)->get();
    }

    public function test_missed_first_response_is_recorded_and_escalated_to_admins_when_unassigned(): void
    {
        $organization = $this->organization();
        $ticket = $this->urgentTicket($organization);

        $this->travel(10)->minutes();
        $this->artisan('sla:detect-breaches')->assertSuccessful();
        $this->assertCount(0, $this->breaches());

        $this->travel(10)->minutes();
        $this->artisan('sla:detect-breaches')->expectsOutputToContain('1 new SLA breach')->assertSuccessful();

        $breach = $this->breaches()->sole();
        $this->assertSame(SlaMetric::FirstResponse, $breach->metric);
        $this->assertSame($ticket->id, $breach->ticket_id);

        $admin = $organization->users()->where('role', Role::Admin)->sole();
        Notification::assertSentTo($admin, SlaBreachedNotification::class);

        app(CurrentOrganization::class)->set($organization);
        $this->assertContains('sla_breached', $ticket->activities()->pluck('type')->map->value->all());
    }

    public function test_repeated_runs_do_not_duplicate_breaches_or_alerts(): void
    {
        $this->urgentTicket($this->organization());
        $this->travel(30)->minutes();
        Event::fake([SlaBreached::class]);

        $this->artisan('sla:detect-breaches');
        $this->artisan('sla:detect-breaches');
        $this->artisan('sla:detect-breaches');

        $this->assertCount(1, $this->breaches());
        Event::assertDispatchedTimes(SlaBreached::class, 1);
    }

    public function test_assignee_is_notified_when_the_ticket_is_assigned(): void
    {
        $organization = $this->organization();
        $ticket = $this->urgentTicket($organization);
        $agent = $this->member($organization);
        app(CurrentOrganization::class)->run($organization, fn () => app(TicketService::class)->update($ticket, ['assignee_id' => $agent->id], $agent));

        $this->travel(30)->minutes();
        $this->artisan('sla:detect-breaches');

        Notification::assertSentTo($agent, SlaBreachedNotification::class);
        Notification::assertNotSentTo($organization->users()->where('role', Role::Admin)->get(), SlaBreachedNotification::class);
    }

    public function test_resolution_breach_ignores_paused_and_resolved_tickets(): void
    {
        $organization = $this->organization();
        $waiting = $this->urgentTicket($organization);
        $solved = $this->urgentTicket($organization);
        $overdue = $this->urgentTicket($organization);
        $agent = $this->member($organization);

        app(CurrentOrganization::class)->run($organization, function () use ($waiting, $solved, $overdue, $agent) {
            $tickets = app(TicketService::class);
            $tickets->reply($waiting, $agent, 'Need more info');                  // pending: clock paused
            $tickets->reply($solved, $agent, 'Fixed', status: TicketStatus::Resolved);
            $tickets->reply($overdue, $agent, 'Looking', status: TicketStatus::Open);
        });

        $this->travel(5)->hours();
        $this->artisan('sla:detect-breaches');

        $resolutionBreaches = $this->breaches()->where('metric', SlaMetric::Resolution);
        $this->assertSame([$overdue->id], $resolutionBreaches->pluck('ticket_id')->all());
        $this->assertCount(0, $this->breaches()->where('metric', SlaMetric::FirstResponse));
    }

    public function test_detection_covers_every_tenant(): void
    {
        $this->urgentTicket($this->organization('Acme'));
        $this->urgentTicket($this->organization('Globex'));
        $this->travel(30)->minutes();

        $this->artisan('sla:detect-breaches');

        $this->assertCount(2, $this->breaches()->pluck('organization_id')->unique());
    }

    public function test_listener_runs_on_the_queue(): void
    {
        Event::fake();
        Event::assertListening(SlaBreached::class, EscalateSlaBreach::class);
        $this->assertContains(ShouldQueue::class, class_implements(EscalateSlaBreach::class));
    }

    public function test_resolved_tickets_are_closed_after_the_grace_period(): void
    {
        $organization = $this->organization();
        $old = $this->urgentTicket($organization);
        $recent = $this->urgentTicket($organization);
        $agent = $this->member($organization);
        $tickets = app(TicketService::class);

        app(CurrentOrganization::class)->run($organization, fn () => $tickets->update($old, ['status' => 'resolved'], $agent));
        $this->travel(6)->days();
        app(CurrentOrganization::class)->run($organization, fn () => $tickets->update($recent, ['status' => 'resolved'], $agent));
        $this->travel(2)->days();

        $this->artisan('tickets:close-resolved')->expectsOutputToContain('Closed 1 ticket')->assertSuccessful();

        $statuses = Ticket::withoutGlobalScope(OrganizationScope::class)->pluck('status', 'id');
        $this->assertSame(TicketStatus::Closed, $statuses[$old->id]);
        $this->assertSame(TicketStatus::Resolved, $statuses[$recent->id]);
    }

    public function test_tasks_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map->command->implode(' ');

        $this->assertStringContainsString('sla:detect-breaches', $commands);
        $this->assertStringContainsString('tickets:close-resolved', $commands);
    }
}
