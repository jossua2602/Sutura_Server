<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the customer said they'd be measured on an online order:
 * own (typed / card photo), saved (profile on file), shop ("have the shop measure me").
 * Null for walk-in and older orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('measurement_source', 10)->nullable()->after('measurement_id');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('measurement_source');
        });
    }
};
