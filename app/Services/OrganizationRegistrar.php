<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a new tenant with its first administrator and sensible defaults,
 * so a new organization can take tickets immediately.
 */
class OrganizationRegistrar
{
    public function __construct(private readonly CurrentOrganization $context) {}

    /**
     * @param  array{organization_name: string, timezone: string, name: string, email: string, password: string}  $data
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $organization = Organization::create([
                'name' => $data['organization_name'],
                'slug' => Str::slug($data['organization_name']).'-'.Str::lower(Str::random(6)),
                'timezone' => $data['timezone'],
            ]);

            $admin = $organization->users()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => Role::Admin,
            ]);

            $this->context->run($organization, function () use ($organization) {
                foreach (TicketPriority::cases() as $priority) {
                    $organization->slaPolicies()->create(['priority' => $priority, ...$priority->defaultTargets()]);
                }

                // Monday to Friday, 09:00-17:00 in the organization's timezone.
                foreach (range(1, 5) as $weekday) {
                    $organization->businessHours()->create(['weekday' => $weekday, 'opens_at' => '09:00', 'closes_at' => '17:00']);
                }
            });

            return $admin;
        });
    }
}
