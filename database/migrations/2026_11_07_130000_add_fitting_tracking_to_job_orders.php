<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ready for Fitting no longer creates a Fitting appointment; the customer is
 * told to visit the shop within a set number of days instead. The order keeps
 * its own fitting record:
 *  - fitting_ready_at: when it last became Ready for Fitting (the visit-by date counts from here)
 *  - fitting_rounds:   how many times it has entered Ready for Fitting (drives the fitting limit)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->timestamp('fitting_ready_at')->nullable();
            $table->unsignedTinyInteger('fitting_rounds')->default(0);
        });

        // Orders that already had fitting appointments keep that count toward the limit.
        $counts = DB::table('appointments')
            ->whereNotNull('job_order_id')
            ->where('appointment_type', 'fitting')
            ->groupBy('job_order_id')
            ->selectRaw('job_order_id, COUNT(*) as n')
            ->pluck('n', 'job_order_id');

        foreach ($counts as $jobId => $n) {
            DB::table('job_orders')->where('id', $jobId)->update(['fitting_rounds' => min(255, (int) $n)]);
        }
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn(['fitting_ready_at', 'fitting_rounds']);
        });
    }
};
