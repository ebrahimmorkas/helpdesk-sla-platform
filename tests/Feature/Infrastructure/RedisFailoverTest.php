<?php

namespace Tests\Feature\Infrastructure;

use App\Enums\Role;
use App\Services\OrganizationRegistrar;
use App\Services\TicketService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Proves the production cache and queue configuration keeps working when Redis
 * refuses connections, by pointing Redis at a closed port.
 */
class RedisFailoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1,
            'database.redis.cache.host' => '127.0.0.1',
            'database.redis.cache.port' => 1,
        ]);
        Redis::purge('default');
        Redis::purge('cache');
    }

    public function test_queued_jobs_fall_back_to_the_database_queue(): void
    {
        config(['queue.default' => 'failover']);

        dispatch(fn () => null);

        $this->assertSame(1, DB::table('jobs')->count());
    }

    /**
     * Regression: notifications marked afterCommit() were handed to the Redis
     * queue, which deferred the real push until commit, outside the failover
     * driver's error handling. The request failed after the data was saved.
     */
    public function test_notifications_sent_from_a_transaction_fall_back_to_the_database_queue(): void
    {
        config(['queue.default' => 'failover']);
        $admin = app(OrganizationRegistrar::class)->register([
            'organization_name' => 'Acme', 'timezone' => 'UTC', 'name' => 'Ada',
            'email' => 'ada@acme.test', 'password' => 'correct-horse-42',
        ]);
        $agent = $this->member($admin->organization);
        app(CurrentOrganization::class)->set($admin->organization);
        $ticket = app(TicketService::class)->open([
            'subject' => 'Help', 'description' => 'Details',
            'requester_id' => $this->member($admin->organization, Role::Customer)->id,
        ], $admin);

        app(TicketService::class)->update($ticket, ['assignee_id' => $agent->id], $admin);

        // One queued job per notification channel (mail and database).
        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertSame($agent->id, $ticket->fresh()->assignee_id);
    }

    public function test_cache_and_locks_fall_back_to_the_database_store(): void
    {
        $store = Cache::store('failover');

        $store->put('report', 'value', 60);
        $this->assertSame('value', $store->get('report'));
        $this->assertTrue($store->lock('idempotency-test', 10)->get());
        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%report')->count());
    }
}
