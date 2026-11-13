<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free trial (2026-11-14): a new shop can ask for a 14-day trial of the plan it picked instead of
 * paying at registration. The admin still approves it; on approval the plan starts as a trial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_applications', function (Blueprint $table) {
            $table->boolean('free_trial')->default(false)->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('store_applications', function (Blueprint $table) {
            $table->dropColumn('free_trial');
        });
    }
};
