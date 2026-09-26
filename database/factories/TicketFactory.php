<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates tickets directly (no SLA calculation, no activity). Application code
 * creates tickets through TicketService.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    private static int $number = 0;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'number' => ++self::$number,
            'requester_id' => fn (array $attributes) => User::factory()->customer()->create(['organization_id' => $attributes['organization_id']]),
            'subject' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Normal,
        ];
    }
}
