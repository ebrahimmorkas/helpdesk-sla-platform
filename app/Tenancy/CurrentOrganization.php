<?php

namespace App\Tenancy;

use App\Models\Organization;
use LogicException;

/**
 * The organization (tenant) the current request or job acts for.
 *
 * Registered as a scoped singleton, so it is reset between requests and
 * between queued jobs on long-running workers.
 */
class CurrentOrganization
{
    private ?int $id = null;

    public function set(Organization|int $organization): void
    {
        $this->id = $organization instanceof Organization ? $organization->id : $organization;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function idOrFail(): int
    {
        return $this->id ?? throw new LogicException('No organization is set for the current context.');
    }

    public function clear(): void
    {
        $this->id = null;
    }

    /**
     * Run a callback in the context of an organization, restoring the previous
     * context afterwards (used by queued jobs and console commands).
     */
    public function run(Organization|int $organization, callable $callback): mixed
    {
        $previous = $this->id;
        $this->set($organization);

        try {
            return $callback();
        } finally {
            $this->id = $previous;
        }
    }
}
