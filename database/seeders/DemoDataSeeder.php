<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationRegistrar;
use App\Services\TicketService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Two tenants with two weeks of ticket history, created through the real
 * services so SLA due dates, pauses and breaches are computed as in production.
 * The clock is moved with Carbon::setTestNow() to place events in the past.
 *
 * Demo accounts use DEMO_USER_PASSWORD, or a random password printed once.
 */
class DemoDataSeeder extends Seeder
{
    private const SUBJECTS = [
        'Cannot log in after password reset', 'Invoice shows the wrong VAT rate', 'CSV export times out',
        'Two-factor code never arrives', 'Webhook deliveries failing with 401', 'Need to add a new admin user',
        'Dashboard charts are empty since this morning', 'Refund not received', 'API rate limit too low for our batch job',
        'Mobile app crashes on startup', 'Cannot upload PDF attachments', 'Change billing email address',
    ];

    public function run(OrganizationRegistrar $registrar, TicketService $tickets, CurrentOrganization $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data must not be seeded in production.');
        }

        $password = config('app.demo_user_password') ?: Str::password(16, symbols: false);
        $realNow = Carbon::now();

        Notification::fake(); // no demo emails

        foreach ([['Northwind Support', 'Europe/London', 'northwind'], ['Contoso Help', 'America/New_York', 'contoso']] as [$name, $timezone, $domain]) {
            Carbon::setTestNow($realNow->copy()->subDays(15));

            $admin = $registrar->register([
                'organization_name' => $name, 'timezone' => $timezone, 'name' => "{$name} Admin",
                'email' => "admin@{$domain}.test", 'password' => $password,
            ]);
            $organization = $admin->organization;

            $agents = collect(range(1, 3))->map(fn ($i) => $this->user($organization, Role::Agent, "agent{$i}@{$domain}.test", $password));
            $customers = collect(range(1, 8))->map(fn ($i) => $this->user($organization, Role::Customer, "customer{$i}@{$domain}.test", $password));

            $context->run($organization, fn () => $this->history($tickets, $agents, $customers, $realNow));

            $this->command?->info("{$name}: admin@{$domain}.test, agent1-3@{$domain}.test, customer1-8@{$domain}.test");
        }

        Carbon::setTestNow();
        Artisan::call('sla:detect-breaches');

        if (! config('app.demo_user_password')) {
            $this->command?->warn("Generated demo password: {$password}");
        }
    }

    private function history(TicketService $tickets, $agents, $customers, Carbon $realNow): void
    {
        $firstDay = $realNow->copy()->subDays(14)->startOfDay();

        foreach (range(1, 30) as $i) {
            // Two tickets a day over the last 14 days, mostly in working hours.
            $openedAt = $firstDay->copy()->addDays(intdiv($i, 2))->setTime(random_int(7, 18), random_int(0, 59));
            if ($openedAt->greaterThan($realNow->copy()->subHours(6))) {
                break;
            }
            Carbon::setTestNow($openedAt);

            $agent = $agents->random();
            $ticket = $tickets->open([
                'subject' => self::SUBJECTS[array_rand(self::SUBJECTS)],
                'description' => fake()->paragraph(),
                'requester_id' => $customers->random()->id,
                'priority' => fake()->randomElement(['low', 'normal', 'normal', 'high', 'urgent']),
            ], $agent);

            if ($i % 4 !== 0) {
                $tickets->update($ticket, ['assignee_id' => $agent->id], $agent);
            }

            if ($i % 5 === 0) {
                continue; // left unanswered
            }

            Carbon::setTestNow(now()->addMinutes(random_int(5, 300)));
            $tickets->reply($ticket, $agent, 'Thanks for reporting this. Could you send a screenshot?');

            if ($i % 3 === 0) {
                Carbon::setTestNow(now()->addHours(random_int(1, 20)));
                $tickets->reply($ticket, $ticket->requester, 'Screenshot attached in the next message.');
                Carbon::setTestNow(now()->addMinutes(random_int(30, 600)));
                $tickets->reply($ticket, $agent, 'Fixed on our side, please confirm.', status: TicketStatus::Resolved);
            }
        }
    }

    private function user(Organization $organization, Role $role, string $email, string $password): User
    {
        return User::factory()->for($organization)->create(['role' => $role, 'email' => $email, 'password' => $password]);
    }
}
