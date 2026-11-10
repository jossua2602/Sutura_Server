<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data fix. A deposit typed in while creating a job order was saved as
 * "pending verification" even though the order's balance already had it
 * taken off. Pressing Verify then subtracted it a second time, so the order
 * showed as fully paid while half was still owed.
 *
 * 1. Deposits that went through that Verify get their amount added back to
 *    the order's balance (the second, wrong subtraction is undone).
 * 2. Deposits still pending are marked verified without touching the balance.
 */
return new class extends Migration
{
    private const NOTE = 'Initial downpayment recorded during order creation.';

    public function up(): void
    {
        DB::transaction(function () {
            $deposits = DB::table('payments')
                ->where('notes', self::NOTE)
                ->whereNull('rejected_at')
                ->get(['id', 'job_order_id', 'amount', 'created_at', 'verified_at', 'recorded_by']);

            foreach ($deposits as $p) {
                if ($p->verified_at === null) {
                    DB::table('payments')->where('id', $p->id)->update([
                        'verified_at' => $p->created_at,
                        'verified_by' => $p->recorded_by,
                    ]);

                    continue;
                }

                // Verified later by the Verify button (the fixed code verifies at
                // creation, in the same second) — that click double-subtracted it.
                $verifiedLater = Carbon::parse($p->verified_at)->gt(Carbon::parse($p->created_at)->addSeconds(5));
                if (! $verifiedLater) {
                    continue;
                }

                $job = DB::table('job_orders')->where('id', $p->job_order_id)->lockForUpdate()->first(['id', 'total_amount', 'balance']);
                if (! $job) {
                    continue;
                }

                $balance = round((float) $job->balance + (float) $p->amount, 2);
                DB::table('job_orders')->where('id', $job->id)->update([
                    'balance' => $balance,
                    'payment_status' => $balance <= 0 ? 'paid' : 'partial',
                ]);
            }
        });
    }

    public function down(): void
    {
        // Data repair only; nothing to undo safely.
    }
};
