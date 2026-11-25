<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service discounts (Services → Discount): the owner sets a percentage and a
 * date window. sale_price stays the discounted base price (the storefront and
 * job pricing already read it); sale_percent lets every price tier of the
 * service get the same percentage off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedTinyInteger('sale_percent')->nullable()->after('sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('sale_percent');
        });
    }
};
