<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The branch a notification is about, so a shop's bell can follow the branch
 * selector and a branch manager's feed stays on their own branch. Filled by
 * App\Notifications\Channels\BranchAwareDatabaseChannel from the job order a
 * notification is about; null = store-wide (subscription, reviews, …), shown
 * in every branch view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('notifiable_id');
            $table->index(['notifiable_id', 'branch_id']);
        });

        // Older rows about a job order take that order's branch.
        $branchByJob = DB::table('job_orders')->pluck('store_branch_id', 'id');
        DB::table('notifications')->where('data', 'like', '%"job_order_id"%')->orderBy('id')
            ->chunk(500, function ($rows) use ($branchByJob) {
                foreach ($rows as $row) {
                    $jobId = json_decode($row->data, true)['job_order_id'] ?? null;
                    $branchId = $jobId ? ($branchByJob[$jobId] ?? null) : null;
                    if ($branchId) {
                        DB::table('notifications')->where('id', $row->id)->update(['branch_id' => $branchId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['notifiable_id', 'branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
