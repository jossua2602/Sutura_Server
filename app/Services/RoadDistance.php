<?php

namespace App\Services;

use App\Support\Geo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Road distance (km by car) from one point to many, using HeiGIT's
 * openrouteservice Matrix API (OpenStreetMap roads, same data as the map's
 * directions). The key stays on the server (ORS_API_KEY).
 *
 * Every answer is cached per origin (rounded to ~100 m) and destination, so
 * the free daily allowance is spent once per spot, not once per page view.
 * With no key, or if the service fails, it returns nothing and callers keep
 * the straight-line distance.
 */
class RoadDistance
{
    // api.openrouteservice.org was retired on 2026-11-24 (HTTP 410); HeiGIT moved it here.
    private const URL = 'https://api.heigit.org/openrouteservice/v2/matrix/driving-car';

    private const CACHE_SECONDS = 604800; // 7 days: roads rarely change

    private const MAX_DESTINATIONS = 49; // one request = 1 origin + 49 shops

    private const TIMEOUT_SECONDS = 4;

    /**
     * For list rows (shops, catalog designs): swaps each row's straight-line
     * `distance_km` for the road km to the same branch (the nearest live one,
     * or the nearest in `$district`) and sets `distance_type` to road or
     * straight. With `$sortByDistance` the rows are re-ordered by the new
     * numbers (within this page; the database still picks the page by
     * straight line, and the radius filter stays straight line too).
     *
     * @param  callable(mixed): iterable  $branchesOf  row => its live branches (latitude, longitude, district)
     */
    public static function applyToRows(Collection $rows, float $lat, float $lng, callable $branchesOf, ?string $district = null, bool $sortByDistance = false): void
    {
        $targets = [];   // "lat,lng" => [lat, lng]: shops share branches, so ask once per branch
        $rowTarget = []; // row index => "lat,lng"
        foreach ($rows as $i => $row) {
            $row->distance_km = $row->distance_km !== null ? round((float) $row->distance_km, 1) : null;
            $row->distance_type = 'straight';

            $best = null;
            $bestKm = INF;
            foreach ($branchesOf($row) ?? [] as $b) {
                if ($b->latitude === null || $b->longitude === null || ($district !== null && $b->district !== $district)) {
                    continue;
                }
                $km = Geo::haversineKm($lat, $lng, (float) $b->latitude, (float) $b->longitude);
                if ($km < $bestKm) {
                    $bestKm = $km;
                    $best = $b;
                }
            }
            if ($best) {
                $key = $best->latitude.','.$best->longitude;
                $targets[$key] = [(float) $best->latitude, (float) $best->longitude];
                $rowTarget[$i] = $key;
            }
        }

        $road = self::kmFrom($lat, $lng, $targets);
        if ($road === []) {
            return;
        }

        foreach ($rows as $i => $row) {
            $key = $rowTarget[$i] ?? null;
            if ($key !== null && isset($road[$key])) {
                $row->distance_km = $road[$key];
                $row->distance_type = 'road';
            }
        }

        if ($sortByDistance) {
            $sorted = $rows->sortBy(fn ($r) => $r->distance_km ?? INF)->values()->all();
            $rows->splice(0, $rows->count(), $sorted);
        }
    }

    /**
     * Sets `road_km` on each branch (the map lists every branch as its own
     * card). Left null where the road service has no answer. Run it before
     * applyToRows() on the same page: the answers are cached, so the nearest-
     * branch lookups that follow cost no extra request.
     *
     * @param  iterable<mixed>  $branches  models with latitude / longitude
     */
    public static function applyToBranches(iterable $branches, float $lat, float $lng): void
    {
        $targets = [];
        $list = [];
        foreach ($branches as $b) {
            $b->road_km = null;
            if ($b->latitude === null || $b->longitude === null) {
                continue;
            }
            $key = $b->latitude.','.$b->longitude;
            $targets[$key] = [(float) $b->latitude, (float) $b->longitude];
            $list[] = [$b, $key];
        }

        $road = self::kmFrom($lat, $lng, $targets);
        foreach ($list as [$b, $key]) {
            $b->road_km = $road[$key] ?? null;
        }
    }

    /**
     * @param  array<int|string, array{0: float, 1: float}>  $destinations  key => [lat, lng]
     * @return array<int|string, float> key => road km (missing keys: no road answer)
     */
    public static function kmFrom(float $lat, float $lng, array $destinations): array
    {
        $key = config('services.openrouteservice.key');
        if (! $key || $destinations === []) {
            return [];
        }

        // ~100 m: close enough for a km figure, and lets nearby visitors share answers.
        $oLat = round($lat, 3);
        $oLng = round($lng, 3);
        $cacheKey = fn (array $d) => sprintf('road_km:%.3f,%.3f:%.5f,%.5f', $oLat, $oLng, $d[0], $d[1]);

        $result = [];
        $missing = [];
        foreach ($destinations as $id => $d) {
            $hit = Cache::get($cacheKey($d));
            if ($hit !== null) {
                $result[$id] = (float) $hit;
            } else {
                $missing[$id] = $d;
            }
        }

        foreach (array_chunk($missing, self::MAX_DESTINATIONS, true) as $chunk) {
            $ids = array_keys($chunk);
            $locations = [[$oLng, $oLat]];
            foreach ($chunk as $d) {
                $locations[] = [(float) $d[1], (float) $d[0]]; // ORS wants [lng, lat]
            }

            try {
                $res = Http::timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders(['Authorization' => $key])
                    ->acceptJson()
                    ->post(self::URL, [
                        'locations' => $locations,
                        'sources' => [0],
                        'destinations' => range(1, count($chunk)),
                        'metrics' => ['distance'],
                        'units' => 'km',
                    ]);
            } catch (Throwable $e) {
                Log::warning('Road distance lookup failed', ['error' => $e->getMessage()]);

                break; // keep straight-line for the rest
            }

            if (! $res->successful()) {
                // 429 = daily/minute allowance used up; straight-line until it resets.
                Log::warning('Road distance lookup refused', ['status' => $res->status()]);

                break;
            }

            $row = $res->json('distances.0') ?? [];
            foreach ($ids as $i => $id) {
                $km = $row[$i] ?? null;
                if (is_numeric($km)) {
                    $result[$id] = round((float) $km, 1);
                    Cache::put($cacheKey($chunk[$id]), $result[$id], self::CACHE_SECONDS);
                }
            }
        }

        return $result;
    }
}
