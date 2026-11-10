<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Shops registered before the PSGC picker are all in Davao City. Give their
// branches the Davao City / Davao del Sur codes, and the barangay code where
// the typed barangay name matches PSGC exactly (ignoring case), so the new
// province/city/barangay search filters include them. Nothing else changes.
return new class extends Migration
{
    private const DAVAO_CITY = '112402000';

    public function up(): void
    {
        if (! DB::table('psgc_areas')->where('code', self::DAVAO_CITY)->exists()) {
            return; // PSGC not imported: run `php artisan app:import-psgc`, then re-run this backfill by hand.
        }
        $province = DB::table('psgc_areas')->where('code', self::DAVAO_CITY)->value('parent_code');
        $provinceName = DB::table('psgc_areas')->where('code', $province)->value('name');
        $barangays = DB::table('psgc_areas')->where('level', 'barangay')->where('city_code', self::DAVAO_CITY)
            ->pluck('code', 'name')->mapWithKeys(fn ($code, $name) => [mb_strtolower(trim($name)) => $code]);

        DB::table('store_branches')
            ->whereNull('city_code')
            ->whereRaw('LOWER(city) LIKE ?', ['%davao%'])
            ->orderBy('id')
            ->each(function ($b) use ($province, $provinceName, $barangays) {
                DB::table('store_branches')->where('id', $b->id)->update([
                    'province' => $b->province ?? $provinceName,
                    'province_code' => $province,
                    'city_code' => self::DAVAO_CITY,
                    'barangay_code' => $b->barangay ? ($barangays[mb_strtolower(trim($b->barangay))] ?? null) : null,
                ]);
            });
    }

    public function down(): void
    {
        // Leaves the codes in place; they're correct for these Davao branches.
    }
};
