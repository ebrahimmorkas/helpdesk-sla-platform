<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Ticket;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\OrganizationScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class OrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_queries_only_return_rows_of_the_current_organization(): void
    {
        [$acme, $globex] = Organization::factory()->count(2)->create();
        Ticket::factory()->count(2)->for($acme)->create();
        Ticket::factory()->count(3)->for($globex)->create();

        app(CurrentOrganization::class)->set($acme);

        $this->assertSame(2, Ticket::count());
        $this->assertSame([$acme->id], Ticket::pluck('organization_id')->unique()->values()->all());
    }

    public function test_queries_fail_closed_without_an_organization_in_context(): void
    {
        Ticket::factory()->count(2)->create();

        $this->assertSame(0, Ticket::count());
        $this->assertSame(2, Ticket::withoutGlobalScope(OrganizationScope::class)->count());
    }

    public function test_new_rows_are_stamped_with_the_current_organization(): void
    {
        $organization = Organization::factory()->create();
        $ticket = Ticket::factory()->for($organization)->create();

        app(CurrentOrganization::class)->run($organization, function () use ($ticket) {
            $message = $ticket->messages()->create([
                'author_id' => $ticket->requester_id,
                'body' => 'Hello',
            ]);

            $this->assertSame($ticket->organization_id, $message->organization_id);
        });
    }

    public function test_creating_a_tenant_row_without_context_is_refused(): void
    {
        $ticket = Ticket::factory()->create();

        $this->expectException(LogicException::class);

        $ticket->messages()->create(['author_id' => $ticket->requester_id, 'body' => 'Hello']);
    }

    public function test_run_restores_the_previous_context(): void
    {
        [$a, $b] = Organization::factory()->count(2)->create();
        $context = app(CurrentOrganization::class);
        $context->set($a);

        $context->run($b, fn () => $this->assertSame($b->id, $context->id()));

        $this->assertSame($a->id, $context->id());
    }

    public function test_ticket_numbers_are_unique_per_organization_only(): void
    {
        [$a, $b] = Organization::factory()->count(2)->create();
        Ticket::factory()->for($a)->create(['number' => 1]);
        Ticket::factory()->for($b)->create(['number' => 1]);

        $this->expectException(QueryException::class);
        Ticket::factory()->for($a)->create(['number' => 1]);
    }
}
