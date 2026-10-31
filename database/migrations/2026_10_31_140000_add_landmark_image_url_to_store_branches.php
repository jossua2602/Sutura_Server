<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A photo of the branch's landmark ("beside Jollibee, across the church"),
// next to the landmark text, so customers can spot it on the way.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_branches', function (Blueprint $table) {
            $table->text('landmark_image_url')->nullable()->after('landmark');
        });
    }

    public function down(): void
    {
        Schema::table('store_branches', function (Blueprint $table) {
            $table->dropColumn('landmark_image_url');
        });
    }
};
