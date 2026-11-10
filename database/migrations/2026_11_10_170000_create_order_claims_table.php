<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * After-sale claims: within 3 days of an order being completed (claimed), the
 * customer can report a problem (e.g. poor stitching) and choose a free repair /
 * alteration or a refund. The shop accepts or declines. A repair becomes its own
 * ₱0 linked job order; a refund is recorded here (SUTURA never moves money).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('filed_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 20);            // repair | refund (the customer's choice)
            $table->string('reason', 30);          // poor_stitching | wrong_fit | damaged | other
            $table->text('description');
            $table->json('photos');                // 1–5 image URLs

            // submitted → declined | repairing → resolved | awaiting_return → refunded
            $table->string('status', 20)->default('submitted');
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decline_reason')->nullable();
            // A declined customer can ask SUTURA support to review it (once).
            $table->foreignId('escalated_ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete();

            // Repair path: the linked ₱0 warranty-repair job order.
            $table->foreignId('repair_job_order_id')->nullable()->constrained('job_orders')->nullOnDelete();

            // Refund path (recorded only; the shop pays outside SUTURA).
            $table->timestamp('garment_returned_at')->nullable();
            $table->decimal('refund_amount', 10, 2)->nullable();
            $table->string('refund_method', 20)->nullable();   // cash | gcash
            $table->string('refund_reference')->nullable();
            $table->text('refund_proof_path')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index('job_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_claims');
    }
};
