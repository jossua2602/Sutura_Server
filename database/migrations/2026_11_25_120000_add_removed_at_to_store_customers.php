<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers → Remove hides a customer from the shop's list (and the customer
 * pickers) without touching their past appointments or orders. Set by
 * CustomerController::destroy; cleared when the shop adds them again. A new
 * appointment or order made after this time brings them back on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_customers', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('store_customers', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
