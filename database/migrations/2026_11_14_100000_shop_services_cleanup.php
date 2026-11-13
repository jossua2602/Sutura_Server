<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shop "Services" clean-up (2026-11-14):
 *  1. services.showcase_category can now hold one of the shop's own services by name
 *     (up to 40 characters), so it is widened from 30 to 60.
 *  2. Men / Women / Kids are no longer shop tags (only the 10 standard services are):
 *     they are removed from stores.specializations.
 *  3. The automatic "draft services" made on approval were dropped: the drafts it made
 *     and nobody touched (still hidden, no price, no description/photo, never used) are deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function ($table) {
            $table->string('showcase_category', 60)->nullable()->change();
        });

        $departments = ['men', 'women', 'children'];
        DB::table('stores')->whereNotNull('specializations')->orderBy('id')->each(function ($store) use ($departments) {
            $tags = json_decode($store->specializations, true);
            if (! is_array($tags)) {
                return;
            }
            $kept = array_values(array_diff($tags, $departments));
            if (count($kept) !== count($tags)) {
                DB::table('stores')->where('id', $store->id)->update(['specializations' => json_encode($kept)]);
            }
        });

        // Only rows exactly as the draft feature left them (it shipped 2026-11-13).
        $drafts = DB::table('services')
            ->where('created_at', '>=', '2026-11-13 00:00:00')
            ->where('is_active', false)
            ->whereNull('base_price')->whereNull('sale_price')
            ->whereNull('description')->whereNull('image_url')
            ->whereNull('estimated_days')
            ->get(['id', 'name', 'tags']);

        foreach ($drafts as $s) {
            $tags = json_decode((string) $s->tags, true);
            $untouched = $tags === [$s->name]
                && ! DB::table('service_pricing')->where('service_id', $s->id)->exists()
                && ! DB::table('job_orders')->where('service_id', $s->id)->exists()
                && ! DB::table('appointments')->where('service_id', $s->id)->exists()
                && ! DB::table('catalog_items')->where('service_id', $s->id)->exists()
                && ! DB::table('service_package_items')->where('service_id', $s->id)->exists();
            if ($untouched) {
                DB::table('services')->where('id', $s->id)->delete();
            }
        }
    }

    public function down(): void
    {
        // Deleted drafts and removed tags are not restored (they were never used).
        Schema::table('services', function ($table) {
            $table->string('showcase_category', 30)->nullable()->change();
        });
    }
};
