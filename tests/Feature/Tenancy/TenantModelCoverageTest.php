<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guard against the most likely tenancy bug: a new model whose table has an
 * organization_id column but which forgets the organization scope.
 */
class TenantModelCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Users are looked up by email before a tenant is known; see User::inCurrentOrganization(). */
    private const EXEMPT = [User::class];

    public function test_every_model_with_an_organization_id_is_tenant_scoped(): void
    {
        $checked = 0;

        foreach (File::files(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();

            if (! is_subclass_of($class, Model::class) || in_array($class, self::EXEMPT, true)) {
                continue;
            }

            if (Schema::hasColumn((new $class)->getTable(), 'organization_id')) {
                $this->assertContains(BelongsToOrganization::class, class_uses_recursive($class), "{$class} must use BelongsToOrganization.");
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(8, $checked);
    }
}
