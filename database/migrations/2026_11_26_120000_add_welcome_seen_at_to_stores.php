<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the shop owner first saw the Welcome Guide. Kept on the shop (not in
 * the browser) so the guide opens once per shop, whichever device the owner
 * signs in on. Set by StoreSetupController::markWelcomeSeen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->timestamp('welcome_seen_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('welcome_seen_at');
        });
    }
};
