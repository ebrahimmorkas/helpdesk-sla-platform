<?php

namespace Tests\Feature\Reports;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use App\Services\OrganizationRegistrar;
use App\Services\TicketService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-01-05 09:00:00');
        $this->admin = app(OrganizationRegistrar::class)->register([
            'organization_name' => 'Acme', 'timezone' => 'Europe/London', 'name' => 'Ada',
            'email' => 'ada@acme.test', 'password' => 'correct-horse-42',
        ]);
    }

    private function open(User $agent, string $priority): Ticket
    {
        return app(CurrentOrganization::class)->run($agent->organization_id, fn () => app(TicketService::class)->open([
            'subject' => 'Issue', 'description' => 'Details', 'priority' => $priority,
            'requester_id' => $this->member($agent->organization, Role::Customer)->id,
        ], $agent));
    }

    public function test_sla_compliance_counts_met_and_breached_targets_per_priority(): void
    {
        $agent = $this->member($this->admin->organization);
        $tickets = app(TicketService::class);

        // Urgent (15 min first response): one answered in time, one answered late, one never answered.
        $onTime = $this->open($agent, 'urgent');
        $late = $this->open($agent, 'urgent');
        $this->open($agent, 'urgent');

        app(CurrentOrganization::class)->run($agent->organization_id, function () use ($tickets, $onTime, $late, $agent) {
            $this->travel(5)->minutes();
            $tickets->reply($onTime, $agent, 'On it', status: TicketStatus::Resolved);
            $this->travel(55)->minutes();
            $tickets->reply($late, $agent, 'Sorry for the delay');
        });

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/reports/sla-compliance?from=2026-01-01&to=2026-01-31')->assertOk();

        $urgent = collect($response->json('data.priorities'))->firstWhere('priority', 'urgent');
        $this->assertSame(3, $urgent['tickets']);
        $this->assertSame(['met' => 1, 'breached' => 2, 'compliance_percent' => 33.3, 'average_minutes' => 33], $urgent['first_response']);
        $this->assertSame(1, $urgent['resolution']['met']);
        $this->assertSame(0, collect($response->json('data.priorities'))->firstWhere('priority', 'low')['tickets']);
    }

    public function test_report_cache_is_isolated_per_organization(): void
    {
        $this->open($this->member($this->admin->organization), 'high');
        $otherAdmin = app(OrganizationRegistrar::class)->register([
            'organization_name' => 'Globex', 'timezone' => 'UTC', 'name' => 'Hank',
            'email' => 'hank@globex.test', 'password' => 'correct-horse-42',
        ]);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/reports/sla-compliance?from=2026-01-01&to=2026-01-31')
            ->assertJsonPath('data.priorities.2.tickets', 1);

        Sanctum::actingAs($otherAdmin);
        $this->getJson('/api/v1/reports/sla-compliance?from=2026-01-01&to=2026-01-31')
            ->assertJsonPath('data.priorities.2.tickets', 0);

        $this->assertTrue(Cache::has("reports:sla-compliance:org:{$this->admin->organization_id}:2026-01-01:2026-01-31"));
    }

    public function test_workload_shows_open_pending_and_overdue_per_agent(): void
    {
        $agent = $this->member($this->admin->organization, Role::Agent, ['name' => 'Zed Agent']);
        $ticket = $this->open($agent, 'urgent');
        $this->open($agent, 'low');
        app(CurrentOrganization::class)->run($agent->organization_id, fn () => app(TicketService::class)->update($ticket, ['assignee_id' => $agent->id], $this->admin));
        $this->travel(5)->hours();

        Sanctum::actingAs($agent);
        $this->getJson('/api/v1/reports/workload')
            ->assertOk()
            ->assertJsonPath('data.agents.1.agent.name', 'Zed Agent')
            ->assertJsonPath('data.agents.1.open', 1)
            ->assertJsonPath('data.agents.1.overdue', 1)
            ->assertJsonPath('data.unassigned.open', 1);
    }

    public function test_customers_cannot_view_reports(): void
    {
        Sanctum::actingAs($this->member($this->admin->organization, Role::Customer));

        $this->getJson('/api/v1/reports/sla-compliance?from=2026-01-01&to=2026-01-31')->assertForbidden();
        $this->getJson('/api/v1/reports/workload')->assertForbidden();
    }

    public function test_database_notifications_are_stored_and_listed(): void
    {
        $agent = $this->member($this->admin->organization);
        $ticket = $this->open($this->admin, 'normal');

        // Real notification channels (no fake): mail goes to the array mailer, database to the table.
        app(CurrentOrganization::class)->run($agent->organization_id, fn () => app(TicketService::class)->update($ticket, ['assignee_id' => $agent->id], $this->admin));

        Sanctum::actingAs($agent);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.data.type', 'ticket_assigned')
            ->assertJsonPath('data.0.data.ticket_number', $ticket->number);
        $this->assertSame(1, $agent->notifications()->where('type', TicketAssignedNotification::class)->count());
    }
}
