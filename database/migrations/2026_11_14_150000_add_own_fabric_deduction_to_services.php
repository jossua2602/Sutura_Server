<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much a service costs less, per piece, when the customer brings their own fabric.
 * Null = no fixed amount (the shop adjusts by hand with a Fabric deduction on the order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->decimal('own_fabric_deduction', 10, 2)->nullable()->after('min_order_qty');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('own_fabric_deduction');
        });
    }
};
