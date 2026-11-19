<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Locked by the plan": after a move to a smaller plan, branch shops, staff
 * accounts and services above its limits are locked, never deleted
 * (App\Support\PlanLimits). Kept apart from the owner's own status /
 * is_active, so an upgrade only ever unlocks what the plan locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['store_branches', 'staff_profiles', 'services'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('plan_locked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['store_branches', 'staff_profiles', 'services'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('plan_locked_at');
            });
        }
    }
};
