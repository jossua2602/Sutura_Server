<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('ready_for_pickup_at');
            // The Completed Orders page filters and sorts on these two.
            $table->index(['store_id', 'status', 'completed_at']);
        });

        // Backfill: jobs already completed have no recorded completion time —
        // updated_at (the last time the row changed) is the closest we have,
        // same approximation the ready_for_pickup_at backfill used.
        DB::table('job_orders')
            ->where('status', 'completed')
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'status', 'completed_at']);
            $table->dropColumn('completed_at');
        });
    }
};
