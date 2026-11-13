<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan payments paid online with GCash (through the payment gateway) next to
 * the manual "GCash receipt" ones, plus refunds of online payments.
 *
 *  channel        receipt (manual upload, checked by an admin) | online (confirmed by the gateway)
 *  gateway_status awaiting (checkout opened, not paid) | paid | expired
 *  gateway_token  random, lets a not-yet-approved applicant (no login) see and retry their payment
 *  refund_*       one refund per payment, full or partial; refund_status pending | succeeded | failed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_upgrade_requests', function (Blueprint $table) {
            $table->string('channel', 20)->default('receipt')->after('payment_method');
            $table->string('gateway_invoice_id', 100)->nullable()->unique()->after('channel');
            $table->text('gateway_checkout_url')->nullable()->after('gateway_invoice_id');
            $table->string('gateway_status', 20)->nullable()->after('gateway_checkout_url');
            $table->timestamp('gateway_paid_at')->nullable()->after('gateway_status');
            $table->string('gateway_token', 64)->nullable()->unique()->after('gateway_paid_at');

            $table->decimal('refund_amount', 10, 2)->nullable()->after('rejection_reason');
            $table->string('refund_status', 20)->nullable()->after('refund_amount');
            $table->string('refund_reason', 500)->nullable()->after('refund_status');
            $table->string('refund_gateway_id', 100)->nullable()->after('refund_reason');
            $table->timestamp('refunded_at')->nullable()->after('refund_gateway_id');
            $table->foreignId('refunded_by')->nullable()->after('refunded_at')->constrained('users')->nullOnDelete();

            $table->index(['channel', 'gateway_status']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_upgrade_requests', function (Blueprint $table) {
            $table->dropIndex(['channel', 'gateway_status']);
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropUnique(['gateway_invoice_id']);
            $table->dropUnique(['gateway_token']);
            $table->dropColumn([
                'channel', 'gateway_invoice_id', 'gateway_checkout_url', 'gateway_status', 'gateway_paid_at', 'gateway_token',
                'refund_amount', 'refund_status', 'refund_reason', 'refund_gateway_id', 'refunded_at',
            ]);
        });
    }
};
