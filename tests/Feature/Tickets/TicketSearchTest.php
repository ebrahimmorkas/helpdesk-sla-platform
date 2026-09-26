<?php

namespace Tests\Feature\Tickets;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationRegistrar;
use App\Services\TicketService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * InnoDB only updates FULLTEXT indexes when a transaction commits, so rows
 * created inside RefreshDatabase's wrapping transaction are not searchable.
 * This test commits real rows and removes them afterwards.
 */
class TicketSearchTest extends TestCase
{
    /** @var list<Organization> */
    private array $organizations = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->organizations as $organization) {
            DB::table('ticket_activities')->where('organization_id', $organization->id)->delete();
            DB::table('tickets')->where('organization_id', $organization->id)->delete();
            $organization->delete();
        }

        parent::tearDown();
    }

    private function organizationWithTickets(string $name, array $tickets): array
    {
        $admin = app(OrganizationRegistrar::class)->register([
            'organization_name' => $name, 'timezone' => 'UTC', 'name' => 'Admin',
            'email' => uniqid('admin-').'@search.test', 'password' => 'correct-horse-42',
        ]);
        $this->organizations[] = $admin->organization;
        $customer = $this->member($admin->organization, Role::Customer);

        app(CurrentOrganization::class)->run($admin->organization, function () use ($tickets, $customer) {
            foreach ($tickets as [$subject, $description]) {
                app(TicketService::class)->open(['subject' => $subject, 'description' => $description], $customer);
            }
        });

        return [$admin, $customer];
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
    }

    public function test_full_text_search_matches_subject_and_description_within_the_tenant(): void
    {
        [$admin] = $this->organizationWithTickets('Acme Search', [
            ['Invoice shows wrong VAT rate', 'The invoice for March has 21% instead of 20%.'],
            ['Cannot log in', 'Password reset email never arrives.'],
            ['Export fails', 'CSV export of invoices times out after a minute.'],
        ]);
        $this->organizationWithTickets('Globex Search', [
            ['Invoice missing', 'Our invoice for April is missing.'],
        ]);

        $this->as($admin);

        $this->getJson('/api/v1/tickets?q=invoice')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject', 'Invoice shows wrong VAT rate');

        $this->getJson('/api/v1/tickets?q=password+email')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/tickets?q=nothing-matches-this')->assertJsonPath('meta.total', 0);
    }

    public function test_customers_only_search_their_own_tickets(): void
    {
        [$admin, $customer] = $this->organizationWithTickets('Acme Customers', [
            ['Broken printer', 'The printer in room 4 is broken.'],
        ]);
        $otherCustomer = $this->member($admin->organization, Role::Customer);

        $this->as($otherCustomer);
        $this->getJson('/api/v1/tickets?q=printer')->assertJsonPath('meta.total', 0);

        $this->as($customer);
        $this->getJson('/api/v1/tickets?q=printer')->assertJsonPath('meta.total', 1);
    }

    public function test_search_terms_are_validated(): void
    {
        [$admin] = $this->organizationWithTickets('Acme Validation', []);
        $this->as($admin);

        $this->getJson('/api/v1/tickets?q=ab')->assertUnprocessable()->assertJsonValidationErrors('q');
    }
}
