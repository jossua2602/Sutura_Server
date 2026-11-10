<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Offer repair instead": on a refund claim the shop can offer a free repair
 * (due date + staff + a note). The customer then accepts the repair or keeps
 * the refund request. One offer per claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_claims', function (Blueprint $table) {
            $table->date('offered_due_date')->nullable()->after('decline_reason');
            $table->foreignId('offered_staff_id')->nullable()->after('offered_due_date')->constrained('users')->nullOnDelete();
            $table->text('offer_note')->nullable()->after('offered_staff_id');
            $table->timestamp('repair_offered_at')->nullable()->after('offer_note');
            $table->timestamp('offer_declined_at')->nullable()->after('repair_offered_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offered_staff_id');
            $table->dropColumn(['offered_due_date', 'offer_note', 'repair_offered_at', 'offer_declined_at']);
        });
    }
};
