<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business minutes spent waiting on the customer. Keeping the total makes the
     * resolution due date derivable at any time: created_at + target + paused.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedInteger('sla_paused_minutes')->default(0)->after('sla_paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('sla_paused_minutes');
        });
    }
};
