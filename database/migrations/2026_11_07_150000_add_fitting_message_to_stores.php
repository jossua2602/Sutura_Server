<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop setting: the message a customer gets when their order is Ready for
 * Fitting. {days} and {date} are filled in (e.g. "within 7 days", "Nov 14").
 * Null = the default message (JobOrder::DEFAULT_FITTING_MESSAGE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('fitting_message', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('fitting_message');
        });
    }
};
