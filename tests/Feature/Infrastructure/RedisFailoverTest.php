<?php

namespace Tests\Feature\Infrastructure;

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

    public function test_cache_and_locks_fall_back_to_the_database_store(): void
    {
        $store = Cache::store('failover');

        $store->put('report', 'value', 60);
        $this->assertSame('value', $store->get('report'));
        $this->assertTrue($store->lock('idempotency-test', 10)->get());
        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%report')->count());
    }
}
