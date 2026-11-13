<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Texts send automatically by default (2026-11-14): shops on "review" move to "auto", and new
 * shops start on "auto". A shop that turned texts "off" stays off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function ($table) {
            $table->string('sms_mode', 10)->default('auto')->change();
        });
        DB::table('stores')->where('sms_mode', 'review')->update(['sms_mode' => 'auto']);
    }

    public function down(): void
    {
        Schema::table('stores', function ($table) {
            $table->string('sms_mode', 10)->default('review')->change();
        });
    }
};
