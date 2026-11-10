<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin "Payments": every GCash payment a shop sends SUTURA, in one ledger.
 *
 * subscription_upgrade_requests already held plan upgrade/renewal payments,
 * so it becomes the ledger (the name `payments` is taken by customer → shop
 * job payments). New here:
 *  - type: new_shop (the application's plan payment) | upgrade | renewal | downgrade
 *  - what the owner reports (amount sent, sender, payment time) and the
 *    normalized reference used for the "one transaction, one payment" rule
 *  - the billing period each approved payment bought
 *  - payment_logs: the timeline of every action on a payment
 *  - platform_settings: the GCash account owners pay to (editable by admins)
 *  - store_subscriptions.scheduled_*: a downgrade that starts when the
 *    current paid period ends (no more instant, free downgrades)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_upgrade_requests', function (Blueprint $table) {
            $table->string('type', 12)->default('upgrade')->after('store_id');
            $table->foreignId('store_application_id')->nullable()->after('type')->constrained('store_applications')->nullOnDelete();
            $table->decimal('amount_sent', 10, 2)->nullable()->after('quoted_price');
            $table->string('sender_name', 100)->nullable()->after('payment_reference');
            $table->string('sender_number', 20)->nullable()->after('sender_name');
            $table->dateTime('paid_at_reported')->nullable()->after('sender_number');
            // Upper-case, no spaces: compares "1234 5678" with "12345678".
            $table->string('reference_key', 100)->nullable()->after('payment_reference');
            $table->timestamp('billing_period_start')->nullable()->after('rejection_reason');
            $table->timestamp('billing_period_end')->nullable()->after('billing_period_start');
            $table->foreignId('resubmission_of_id')->nullable()->after('billing_period_end')
                ->constrained('subscription_upgrade_requests')->nullOnDelete();
            $table->index('reference_key');
            $table->index(['status', 'created_at']);
            $table->index('type');
        });

        // A new shop's payment reuses the application's receipt file.
        Schema::table('subscription_upgrade_requests', function (Blueprint $table) {
            $table->text('payment_receipt_path')->nullable()->change();
        });

        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('subscription_upgrade_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30); // submitted | resubmitted | approved | rejected | receipt_replaced | scheduled_change_applied
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['payment_id', 'created_at']);
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        // The account that was hard-coded in the frontend until now.
        DB::table('platform_settings')->insert([
            ['key' => 'gcash_name', 'value' => 'JWA', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'gcash_number', 'value' => '09777044683', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('store_subscriptions', function (Blueprint $table) {
            $table->foreignId('scheduled_plan_id')->nullable()->after('plan_id')->constrained('subscription_plans')->nullOnDelete();
            $table->string('scheduled_billing_cycle', 10)->nullable()->after('scheduled_plan_id');
            $table->foreignId('scheduled_payment_id')->nullable()->after('scheduled_billing_cycle')
                ->constrained('subscription_upgrade_requests')->nullOnDelete();
        });

        $this->backfill();
    }

    /** Existing upgrade requests get their keys/periods; existing applications become new_shop payments. */
    private function backfill(): void
    {
        foreach (DB::table('subscription_upgrade_requests')->get() as $row) {
            $days = $row->billing_cycle === 'yearly' ? 365 : 30;
            DB::table('subscription_upgrade_requests')->where('id', $row->id)->update([
                'reference_key' => $row->payment_reference ? strtoupper(preg_replace('/\s+/', '', $row->payment_reference)) : null,
                'billing_period_start' => $row->status === 'approved' ? $row->reviewed_at : null,
                'billing_period_end' => $row->status === 'approved' && $row->reviewed_at
                    ? date('Y-m-d H:i:s', strtotime($row->reviewed_at) + $days * 86400) : null,
            ]);
            DB::table('payment_logs')->insert(['payment_id' => $row->id, 'user_id' => $row->requested_by, 'action' => 'submitted', 'created_at' => $row->created_at]);
            if ($row->status !== 'pending' && $row->reviewed_at) {
                DB::table('payment_logs')->insert([
                    'payment_id' => $row->id, 'user_id' => $row->reviewed_by, 'action' => $row->status,
                    'note' => $row->rejection_reason, 'created_at' => $row->reviewed_at,
                ]);
            }
        }

        $applications = DB::table('store_applications')
            ->join('stores', 'stores.id', '=', 'store_applications.store_id')
            ->whereNotNull('store_applications.payment_receipt_path')
            ->whereNotNull('store_applications.requested_plan_id')
            ->get(['store_applications.*', 'stores.status as store_status', 'stores.rejection_reason as store_rejection']);

        foreach ($applications as $a) {
            $status = match ($a->store_status) { 'approved' => 'approved', 'rejected' => 'rejected', default => 'pending' };
            $decided = $status !== 'pending' ? ($a->reviewed_at ?? $a->updated_at) : null;
            $months = $a->billing_cycle === 'yearly' ? 12 : 1;
            $id = DB::table('subscription_upgrade_requests')->insertGetId([
                'store_id' => $a->store_id,
                'type' => 'new_shop',
                'store_application_id' => $a->id,
                'requested_by' => $a->user_id,
                'plan_id' => $a->requested_plan_id,
                'billing_cycle' => $a->billing_cycle === 'yearly' ? 'yearly' : 'monthly',
                'quoted_price' => $a->quoted_price ?? 0,
                'payment_method' => $a->payment_method ?? 'gcash',
                'payment_receipt_path' => $a->payment_receipt_path,
                'status' => $status,
                'reviewed_by' => $status !== 'pending' ? $a->reviewed_by : null,
                'reviewed_at' => $decided,
                'rejection_reason' => $status === 'rejected' ? 'Shop application rejected: '.($a->store_rejection ?? 'see the application') : null,
                'billing_period_start' => $status === 'approved' ? $decided : null,
                'billing_period_end' => $status === 'approved' && $decided ? date('Y-m-d H:i:s', strtotime("+{$months} month", strtotime($decided))) : null,
                'created_at' => $a->created_at,
                'updated_at' => $a->updated_at,
            ]);
            DB::table('payment_logs')->insert(['payment_id' => $id, 'user_id' => $a->user_id, 'action' => 'submitted', 'note' => 'Sent with the shop application.', 'created_at' => $a->created_at]);
            if ($decided) {
                DB::table('payment_logs')->insert([
                    'payment_id' => $id, 'user_id' => $a->reviewed_by, 'action' => $status,
                    'note' => $status === 'approved' ? 'Decided with the shop application.' : 'Shop application rejected.', 'created_at' => $decided,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('store_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scheduled_payment_id');
            $table->dropConstrainedForeignId('scheduled_plan_id');
            $table->dropColumn('scheduled_billing_cycle');
        });
        DB::table('subscription_upgrade_requests')->where('type', 'new_shop')->delete();
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('payment_logs');
        Schema::table('subscription_upgrade_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resubmission_of_id');
            $table->dropConstrainedForeignId('store_application_id');
            $table->dropIndex(['reference_key']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'amount_sent', 'sender_name', 'sender_number', 'paid_at_reported', 'reference_key', 'billing_period_start', 'billing_period_end']);
        });
    }
};
