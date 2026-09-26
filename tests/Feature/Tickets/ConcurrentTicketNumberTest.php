<?php

namespace Tests\Feature\Tickets;

use App\Enums\Role;
use App\Models\Organization;
use App\Services\OrganizationRegistrar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Commits real rows (other processes must see them) and removes them afterwards.
 */
class ConcurrentTicketNumberTest extends TestCase
{
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate');
        $this->organization = app(OrganizationRegistrar::class)->register([
            'organization_name' => 'Parallel Co', 'timezone' => 'UTC', 'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@parallel.test', 'password' => 'correct-horse-42',
        ])->organization;
    }

    protected function tearDown(): void
    {
        DB::table('ticket_activities')->where('organization_id', $this->organization->id)->delete();
        DB::table('tickets')->where('organization_id', $this->organization->id)->delete();
        $this->organization->delete();

        parent::tearDown();
    }

    public function test_parallel_requests_receive_distinct_sequential_ticket_numbers(): void
    {
        $customer = $this->member($this->organization, Role::Customer);

        $results = Process::pool(function ($pool) use ($customer) {
            foreach (range(1, 8) as $i) {
                $pool->path(base_path())->command([PHP_BINARY, 'tests/Support/open-ticket.php', $customer->id]);
            }
        })->start()->wait();

        $numbers = collect($results)->map(fn ($result) => (int) trim($result->output()))->sort()->values()->all();

        $this->assertSame(range(1, 8), $numbers);
        $this->assertSame(8, $this->organization->fresh()->ticket_sequence);
    }
}
