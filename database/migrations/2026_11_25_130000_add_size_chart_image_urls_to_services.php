<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A service's Reference Chart can hold several images (e.g. Men's and Women's
 * sizing). size_chart_image_url stays as the first one, so anything that only
 * reads the single field still shows a chart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->json('size_chart_image_urls')->nullable()->after('size_chart_image_url');
        });

        // Carry each existing single chart image over as a one-image list.
        DB::table('services')->whereNotNull('size_chart_image_url')->where('size_chart_image_url', '!=', '')
            ->orderBy('id')->each(function ($s) {
                DB::table('services')->where('id', $s->id)->update(['size_chart_image_urls' => json_encode([$s->size_chart_image_url])]);
            });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('size_chart_image_urls');
        });
    }
};
