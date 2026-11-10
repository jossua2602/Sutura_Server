<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TIN ID / BIR 2303 is now "Recommended" on the shop application, not
// required: many small tailors aren't BIR-registered yet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_applications', function (Blueprint $table) {
            $table->text('tin_id_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversible once applications without a TIN exist.
    }
};
