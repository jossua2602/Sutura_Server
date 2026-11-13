<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Office / School / Medical / Maritime are merged into one "Uniform" service (2026-11-14);
 * the kind of uniform becomes the service's type (service_leaf_type), which customers filter by.
 *  - stores.specializations: any of the four → "uniform" (once).
 *  - services filed under one of the four → category "uniform", type from the old group.
 *  - old types: corporate → office, institutional → other.
 *  - uniform services still without a type get one guessed from their name ("PNP Uniform" → PNP / Police).
 */
return new class extends Migration
{
    private const OLD_GROUPS = [
        'school' => 'school_uniforms',
        'office' => 'office_uniforms',
        'medical' => 'medical_uniforms',
        'maritime' => 'maritime_uniforms',
    ];

    /** Same order as the client's guessUniformType(): the first match wins. */
    private const GUESSES = [
        'police_uniforms' => '/\b(pnp|police|pulis)\b/i',
        'fire_uniforms' => '/\b(bfp|fire|bombero)\b/i',
        'military_uniforms' => '/\b(afp|army|military|navy|air force|rotc)\b/i',
        'security_uniforms' => '/\b(security|guard)\b/i',
        'maritime_uniforms' => '/\b(maritime|seaman|seafarer|marine)\b/i',
        'medical_uniforms' => '/\b(medical|nurse|nursing|scrubs?|hospital|doctor|lab coat)\b/i',
        'school_uniforms' => '/\b(school|student|deped|p\.?e\.?)\b/i',
        'hospitality_uniforms' => '/\b(hotel|restaurant|chef|waiter|resort|hospitality)\b/i',
        'office_uniforms' => '/\b(office|corporate|company|bank)\b/i',
    ];

    public function up(): void
    {
        DB::table('stores')->whereNotNull('specializations')->orderBy('id')->each(function ($store) {
            $tags = json_decode($store->specializations, true);
            if (! is_array($tags) || ! array_intersect($tags, array_keys(self::OLD_GROUPS))) {
                return;
            }
            $kept = array_values(array_diff($tags, array_keys(self::OLD_GROUPS)));
            if (! in_array('uniform', $kept, true)) {
                $kept[] = 'uniform';
            }
            DB::table('stores')->where('id', $store->id)->update(['specializations' => json_encode($kept)]);
        });

        foreach (self::OLD_GROUPS as $group => $type) {
            DB::table('services')->where('showcase_category', $group)->update([
                'showcase_category' => 'uniform',
                'service_category' => 'uniform_production',
                'service_leaf_type' => DB::raw("CASE WHEN service_leaf_type IS NULL OR service_leaf_type = '' THEN '{$type}' ELSE service_leaf_type END"),
            ]);
        }

        // Anything shown under Uniform searches as Uniform Production (so it gets a type below).
        DB::table('services')->where('showcase_category', 'uniform')
            ->where(fn ($q) => $q->whereNull('service_category')->orWhere('service_category', '!=', 'uniform_production'))
            ->update(['service_category' => 'uniform_production', 'service_leaf_type' => null]);

        DB::table('services')->where('service_leaf_type', 'corporate_uniforms')->update(['service_leaf_type' => 'office_uniforms']);
        DB::table('services')->where('service_leaf_type', 'institutional_uniforms')->update(['service_leaf_type' => 'other_uniforms']);

        DB::table('services')
            ->where('service_category', 'uniform_production')
            ->where(fn ($q) => $q->whereNull('service_leaf_type')->orWhere('service_leaf_type', ''))
            ->orderBy('id')
            ->each(function ($s) {
                $type = 'other_uniforms';
                foreach (self::GUESSES as $candidate => $pattern) {
                    if (preg_match($pattern, (string) $s->name)) {
                        $type = $candidate;
                        break;
                    }
                }
                DB::table('services')->where('id', $s->id)->update(['service_leaf_type' => $type]);
            });
    }

    public function down(): void
    {
        // Not reversible: the four groups were merged on purpose.
    }
};
