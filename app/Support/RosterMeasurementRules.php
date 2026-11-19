<?php

namespace App\Support;

/**
 * Validation rules for the body measurements a walk-in bulk order keeps on each
 * team_roster row (one row per person). Laravel's validated() drops any array key
 * without a rule, so these must be listed or the measurements are silently lost.
 * Same limits as an online order's member measurements: numbers above 0, up to 200.
 */
class RosterMeasurementRules
{
    public const MEASUREMENT_KEYS = ['chest', 'waist', 'hips', 'shoulders', 'top_length', 'pants_length', 'neck', 'sleeve', 'inseam'];

    /** @return array<string, array<int, string>> */
    public static function rules(string $prefix = 'custom_order_data.team_roster.*'): array
    {
        $rules = [
            "$prefix.unit" => ['nullable', 'in:in,cm'],
            "$prefix.measure_at_shop" => ['nullable', 'boolean'],
        ];
        foreach (self::MEASUREMENT_KEYS as $key) {
            $rules["$prefix.$key"] = ['nullable', 'numeric', 'gt:0', 'max:200'];
        }

        return $rules;
    }
}
