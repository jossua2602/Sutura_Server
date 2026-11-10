<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Province → City/Municipality → District → Barangay, from psgc_areas
 * (PSA PSGC, see app:import-psgc).
 *
 * "District" is PSGC's sub-municipality (only Manila has them), except for
 * Davao City: PSGC gives it none, but SUTURA's customer search filters Davao
 * shops by its districts, so those are offered there (optional, code
 * "davao:<name>", stored by name only).
 */
class Psgc
{
    public const DAVAO_CITY = '112402000';

    /** Davao City districts used by the customer district filter. */
    public const DAVAO_DISTRICTS = ['Poblacion', 'Talomo', 'Buhangin', 'Agdao', 'Toril', 'Bunawan', 'Calinan', 'Tugbok'];

    private const CACHE_SECONDS = 86400;

    /** @return array<int, array{code: string, name: string}> */
    public static function provinces(): array
    {
        return self::list('province', null);
    }

    public static function cities(string $provinceCode): array
    {
        return self::list('city', $provinceCode);
    }

    /** Districts of a city: PSGC sub-municipalities, or Davao City's own list. */
    public static function districts(string $cityCode): array
    {
        if ($cityCode === self::DAVAO_CITY) {
            return array_map(fn ($name) => ['code' => 'davao:'.$name, 'name' => $name], self::DAVAO_DISTRICTS);
        }

        return self::list('district', $cityCode);
    }

    /** True when the barangay list depends on the district (Manila). */
    public static function districtRequired(string $cityCode): bool
    {
        return $cityCode !== self::DAVAO_CITY && self::districts($cityCode) !== [];
    }

    /** Barangays of a city, or of one of its PSGC districts. Davao districts don't narrow it. */
    public static function barangays(string $cityCode, ?string $districtCode = null): array
    {
        if ($districtCode && ! str_starts_with($districtCode, 'davao:')) {
            return self::list('barangay', $districtCode);
        }

        return Cache::remember(self::key("barangays:city:{$cityCode}"), self::CACHE_SECONDS, fn () => DB::table('psgc_areas')
            ->where('level', 'barangay')->where('city_code', $cityCode)
            ->orderBy('name')->get(['code', 'name'])
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name])->all());
    }

    /**
     * Checks that the four picks belong together and returns their names, or
     * an error message per field. Only listed values pass — no free text.
     *
     * @return array{ok: bool, errors: array<string, string>, names: array<string, ?string>}
     */
    public static function resolve(?string $province, ?string $city, ?string $district, ?string $barangay): array
    {
        $errors = [];
        $names = ['province' => null, 'city' => null, 'district' => null, 'barangay' => null];
        $find = fn (string $level, ?string $code, ?string $parent) => $code ? DB::table('psgc_areas')
            ->where('level', $level)->where('code', $code)
            ->when($parent !== null, fn ($q) => $q->where('parent_code', $parent))
            ->value('name') : null;

        $names['province'] = $find('province', $province, null);
        if (! $names['province']) {
            $errors['province_code'] = 'Choose your province from the list.';

            return ['ok' => false, 'errors' => $errors, 'names' => $names];
        }

        $names['city'] = $find('city', $city, $province);
        if (! $names['city']) {
            $errors['city_code'] = 'Choose a city or municipality in '.$names['province'].'.';

            return ['ok' => false, 'errors' => $errors, 'names' => $names];
        }

        if ($district) {
            $names['district'] = collect(self::districts($city))->firstWhere('code', $district)['name'] ?? null;
            if (! $names['district']) {
                $errors['district_code'] = 'Choose a district of '.$names['city'].'.';
            }
        } elseif (self::districtRequired($city)) {
            $errors['district_code'] = 'Choose the district in '.$names['city'].'.';
        }

        $inArea = $barangay ? DB::table('psgc_areas')->where('level', 'barangay')->where('code', $barangay)->where('city_code', $city)
            ->when($district && ! str_starts_with($district, 'davao:'), fn ($q) => $q->where('parent_code', $district))
            ->value('name') : null;
        $names['barangay'] = $inArea;
        if (! $inArea) {
            // Only a PSGC district (Manila) narrows the barangays; Davao's don't.
            $area = $district && ! str_starts_with($district, 'davao:') && $names['district'] ? $names['district'] : $names['city'];
            $errors['barangay_code'] = $barangay
                ? 'That barangay isn’t in '.$area.'. Choose one from the list.'
                : 'Choose your barangay from the list.';
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'names' => $names];
    }

    /** Branch columns for a resolved address. Davao districts have no PSGC code. */
    public static function branchColumns(array $codes, array $names, string $street): array
    {
        $district = $codes['district_code'] ?? null;

        return [
            'street' => $street,
            'address' => self::displayAddress($street, $names),
            'province' => $names['province'],
            'province_code' => $codes['province_code'],
            'city' => $names['city'],
            'city_code' => $codes['city_code'],
            'district' => $names['district'],
            'district_code' => $district && ! str_starts_with($district, 'davao:') ? $district : null,
            'barangay' => $names['barangay'],
            'barangay_code' => $codes['barangay_code'],
        ];
    }

    /** "Street, Barangay, City/Municipality, District (if any), Province". */
    public static function displayAddress(string $street, array $names): string
    {
        $parts = [];
        foreach ([$street, $names['barangay'] ?? null, $names['city'] ?? null, $names['district'] ?? null, $names['province'] ?? null] as $part) {
            $part = trim((string) $part);
            if ($part !== '' && ! in_array(mb_strtolower($part), array_map('mb_strtolower', $parts), true)) {
                $parts[] = $part;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Public search/map filter: ?province_code= / ?city_code= / ?barangay_code=
     * keep only shops with a live branch in that area (indexed columns).
     */
    public static function applyLocationFilter($query, \Illuminate\Http\Request $request, string $relation): void
    {
        $filters = array_filter([
            'province_code' => $request->query('province_code'),
            'city_code' => $request->query('city_code'),
            'barangay_code' => $request->query('barangay_code'),
        ], fn ($v) => is_string($v) && preg_match('/^\d{9,10}$/', $v));

        if ($filters) {
            $query->whereHas($relation, function ($b) use ($filters) {
                foreach ($filters as $column => $value) {
                    $b->where($column, $value);
                }
                $b->live();
            });
        }
    }

    /** Cache keys carry the import version, so a re-import shows new data at once. */
    private static function key(string $suffix): string
    {
        return 'psgc:'.Cache::get('psgc:version', 0).':'.$suffix;
    }

    private static function list(string $level, ?string $parent): array
    {
        return Cache::remember(self::key("{$level}:".($parent ?? 'all')), self::CACHE_SECONDS, fn () => DB::table('psgc_areas')
            ->where('level', $level)
            ->when($parent !== null, fn ($q) => $q->where('parent_code', $parent))
            ->orderBy('name')->get(['code', 'name'])
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name])->all());
    }
}
