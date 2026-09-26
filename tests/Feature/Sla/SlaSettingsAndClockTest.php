<?php

namespace Tests\Feature\Sla;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Models\BusinessHour;
use App\Models\Organization;
use App\Models\Ticket;
use App\Services\OrganizationRegistrar;
use App\Services\SlaClock;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlaSettingsAndClockTest extends TestCase
{
    use RefreshDatabase;

    private function registeredOrganization(string $timezone = 'Europe/London'): Organization
    {
        return app(OrganizationRegistrar::class)->register([
            'organization_name' => 'Acme', 'timezone' => $timezone, 'name' => 'Ada',
            'email' => fake()->unique()->safeEmail(), 'password' => 'correct-horse-42',
        ])->organization;
    }

    public function test_admin_updates_an_sla_policy(): void
    {
        $this->actingAsMember($this->registeredOrganization(), Role::Admin);

        $this->putJson('/api/v1/sla-policies/urgent', ['first_response_minutes' => 10, 'resolution_minutes' => 120])
            ->assertOk()
            ->assertJsonPath('data.first_response_minutes', 10);

        $this->getJson('/api/v1/sla-policies')->assertJsonPath('data.0.priority', 'urgent');
        $this->putJson('/api/v1/sla-policies/critical', ['first_response_minutes' => 1, 'resolution_minutes' => 2])->assertNotFound();
        $this->putJson('/api/v1/sla-policies/low', ['first_response_minutes' => 100, 'resolution_minutes' => 50])
            ->assertUnprocessable()->assertJsonValidationErrors('resolution_minutes');
    }

    public function test_admin_replaces_business_hours_without_touching_other_tenants(): void
    {
        $other = $this->registeredOrganization();
        $this->actingAsMember($this->registeredOrganization(), Role::Admin);

        $this->putJson('/api/v1/business-hours', [
            'timezone' => 'Asia/Kolkata',
            'days' => [['weekday' => 1, 'opens_at' => '10:00', 'closes_at' => '18:30']],
        ])->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Kolkata')
            ->assertJsonCount(1, 'data.days')
            ->assertJsonPath('data.days.0.closes_at', '18:30');

        $this->assertSame(5, BusinessHour::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $other->id)->count());
    }

    public function test_business_hours_validation(): void
    {
        $this->actingAsMember($this->registeredOrganization(), Role::Admin);

        $this->putJson('/api/v1/business-hours', [
            'timezone' => 'Nowhere/City',
            'days' => [
                ['weekday' => 8, 'opens_at' => '18:00', 'closes_at' => '09:00'],
                ['weekday' => 8, 'opens_at' => '9am', 'closes_at' => '17:00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'timezone', 'days.0.weekday', 'days.0.closes_at', 'days.1.weekday', 'days.1.opens_at',
        ]);
    }

    public function test_agents_can_read_but_not_change_sla_settings(): void
    {
        $this->actingAsMember($this->registeredOrganization(), Role::Agent);

        $this->getJson('/api/v1/sla-policies')->assertOk();
        $this->putJson('/api/v1/sla-policies/low', ['first_response_minutes' => 1, 'resolution_minutes' => 2])->assertForbidden();
        $this->putJson('/api/v1/business-hours', ['timezone' => 'UTC', 'days' => []])->assertForbidden();
    }

    public function test_customers_cannot_read_sla_settings(): void
    {
        $this->actingAsMember($this->registeredOrganization(), Role::Customer);

        $this->getJson('/api/v1/sla-policies')->assertForbidden();
    }

    public function test_clock_schedules_due_dates_in_business_time(): void
    {
        $organization = $this->registeredOrganization();
        app(CurrentOrganization::class)->set($organization);

        // Friday 16:30 London; High priority = 60 min first response, 480 min resolution.
        $ticket = Ticket::factory()->for($organization)->make([
            'priority' => TicketPriority::High,
            'created_at' => CarbonImmutable::parse('2026-01-09 16:30', 'UTC'),
        ]);
        app(SlaClock::class)->schedule($ticket);

        $this->assertEquals(CarbonImmutable::parse('2026-01-12 09:30', 'UTC'), $ticket->first_response_due_at);
        $this->assertEquals(CarbonImmutable::parse('2026-01-12 16:30', 'UTC'), $ticket->resolution_due_at);
    }

    public function test_waiting_on_the_customer_pushes_the_resolution_due_date(): void
    {
        $organization = $this->registeredOrganization();
        app(CurrentOrganization::class)->set($organization);
        $clock = app(SlaClock::class);

        $ticket = Ticket::factory()->for($organization)->make([
            'priority' => TicketPriority::High,
            'created_at' => CarbonImmutable::parse('2026-01-05 09:00', 'UTC'),
        ]);
        $clock->schedule($ticket);
        $this->assertEquals(CarbonImmutable::parse('2026-01-05 17:00', 'UTC'), $ticket->resolution_due_at);

        // Waiting on the customer from 10:00 to 12:00 Monday = 120 business minutes.
        $clock->pause($ticket, CarbonImmutable::parse('2026-01-05 10:00', 'UTC'));
        $clock->resume($ticket, CarbonImmutable::parse('2026-01-05 12:00', 'UTC'));

        $this->assertSame(120, $ticket->sla_paused_minutes);
        $this->assertNull($ticket->sla_paused_at);
        $this->assertEquals(CarbonImmutable::parse('2026-01-06 11:00', 'UTC'), $ticket->resolution_due_at);
        // First response target is unaffected by waiting on the customer.
        $this->assertEquals(CarbonImmutable::parse('2026-01-05 10:00', 'UTC'), $ticket->first_response_due_at);
    }

    public function test_pausing_outside_business_hours_adds_no_time(): void
    {
        $organization = $this->registeredOrganization();
        app(CurrentOrganization::class)->set($organization);
        $clock = app(SlaClock::class);
        $ticket = Ticket::factory()->for($organization)->make([
            'priority' => TicketPriority::Normal,
            'created_at' => CarbonImmutable::parse('2026-01-09 09:00', 'UTC'),
        ]);
        $clock->schedule($ticket);
        $before = $ticket->resolution_due_at;

        $clock->pause($ticket, CarbonImmutable::parse('2026-01-09 17:00', 'UTC'));
        $clock->resume($ticket, CarbonImmutable::parse('2026-01-12 09:00', 'UTC'));

        $this->assertSame(0, $ticket->sla_paused_minutes);
        $this->assertEquals($before, $ticket->resolution_due_at);
    }
}
