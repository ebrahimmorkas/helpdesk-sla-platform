<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The default ticket list is "newest first" within one organization.
     * Without this index MySQL sorted every ticket of the tenant (filesort) for
     * each page. InnoDB appends the primary key, which also serves the id
     * tie-breaker.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'created_at']);
        });
    }
};
