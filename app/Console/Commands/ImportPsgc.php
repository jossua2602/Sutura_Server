<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Philippine Standard Geographic Code (PSA) → psgc_areas, the source of the
 * Province / City / District / Barangay pickers.
 *
 *   php artisan app:import-psgc             import the copy saved in the repo
 *   php artisan app:import-psgc --download  refresh that copy from the public
 *                                           PSGC API first, then import it
 *
 * The repo copy (database/data/psgc.json) means the form never depends on a
 * third-party API being online. Re-run with --download when the PSA publishes
 * a new PSGC release, then commit the updated file.
 */
class ImportPsgc extends Command
{
    protected $signature = 'app:import-psgc {--download : Fetch the latest PSGC from the public API before importing}';

    protected $description = 'Imports Philippine provinces, cities/municipalities, districts and barangays (PSGC).';

    public const SOURCE = 'https://psgc.gitlab.io/api';

    public static function dataPath(): string
    {
        return database_path('data/psgc.json');
    }

    public function handle(): int
    {
        if ($this->option('download')) {
            $this->info('Downloading PSGC from '.self::SOURCE.' …');
            $this->download();
        }

        if (! is_file(self::dataPath())) {
            $this->error('No PSGC data at '.self::dataPath().'. Run with --download first.');

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents(self::dataPath()), true, flags: JSON_THROW_ON_ERROR);
        $rows = self::rowsFrom($data);

        DB::transaction(function () use ($rows) {
            DB::table('psgc_areas')->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('psgc_areas')->insert($chunk);
            }
        });

        // New cache keys for the pickers (App\Support\Psgc).
        \Illuminate\Support\Facades\Cache::forever('psgc:version', now()->timestamp);

        $count = collect($rows)->countBy('level');
        $this->info(sprintf(
            'PSGC imported (%s): %d provinces, %d cities/municipalities, %d districts, %d barangays.',
            $data['fetched_at'] ?? 'unknown date', $count['province'] ?? 0, $count['city'] ?? 0, $count['district'] ?? 0, $count['barangay'] ?? 0,
        ));

        return self::SUCCESS;
    }

    /** Fetches the PSGC lists and saves only the fields the app uses. */
    private function download(): void
    {
        $get = fn (string $file) => Http::timeout(120)->retry(3, 2000)->acceptJson()->get(self::SOURCE."/{$file}.json")->throw()->json();

        $regions = $get('regions');
        $provinces = $get('provinces');
        $cities = $get('cities-municipalities');
        $districts = $get('sub-municipalities');
        $barangays = $get('barangays');

        $code = fn ($v) => is_string($v) && $v !== '' ? $v : null;

        $data = [
            'source' => self::SOURCE.' (Philippine Statistics Authority PSGC)',
            'fetched_at' => now()->toDateString(),
            'regions' => array_map(fn ($r) => [$r['code'], $r['name']], $regions),
            'provinces' => array_map(fn ($p) => [$p['code'], $p['name'], $p['regionCode']], $provinces),
            // [code, name, province code (null for NCR / independent cities), region code]
            'cities' => array_map(fn ($c) => [$c['code'], $c['name'], $code($c['provinceCode']), $c['regionCode']], $cities),
            // Sub-municipalities (e.g. Manila's Tondo, Sampaloc): [code, name, city code]
            'districts' => array_map(fn ($d) => [$d['code'], $d['name'], $code($d['districtCode'])], $districts),
            // [code, name, city/municipality code, sub-municipality code]
            'barangays' => array_map(fn ($b) => [
                $b['code'], $b['name'], $code($b['cityCode']) ?? $code($b['municipalityCode']), $code($b['subMunicipalityCode']),
            ], $barangays),
        ];

        if (count($data['provinces']) < 50 || count($data['barangays']) < 40000) {
            throw new \RuntimeException('The downloaded PSGC looks incomplete; the saved copy was left unchanged.');
        }

        if (! is_dir(dirname(self::dataPath()))) {
            mkdir(dirname(self::dataPath()), 0755, true);
        }
        file_put_contents(self::dataPath(), json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->info('Saved '.self::dataPath());
    }

    /**
     * Rows for psgc_areas. "Province" level also holds the two places PSGC
     * doesn't put in a province: Metro Manila (its region code) and the
     * independent cities outside a province (Isabela, Cotabato), each under
     * its region code, so every city has a parent the picker can show.
     */
    public static function rowsFrom(array $data): array
    {
        $regions = collect($data['regions'])->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);
        $rows = [];
        $add = function (string $code, string $name, string $level, ?string $parent = null, ?string $city = null) use (&$rows) {
            $rows[$code] = ['code' => $code, 'name' => $name, 'level' => $level, 'parent_code' => $parent, 'city_code' => $city];
        };

        foreach ($data['provinces'] as [$code, $name]) {
            $add($code, $name, 'province');
        }

        $orphans = [];
        foreach ($data['cities'] as [$code, $name, $province, $region]) {
            $parent = $province;
            if (! $parent) {
                $parent = $region;
                $orphans[$region][] = $name;
            }
            $add($code, $name, 'city', $parent);
        }
        foreach ($orphans as $region => $names) {
            // Numeric-string keys come back as ints ("130000000" → 130000000).
            $region = str_pad((string) $region, 9, '0', STR_PAD_LEFT);
            $label = $region === '130000000'
                ? 'Metro Manila (NCR)'
                : implode(' / ', $names).' (independent city, '.($regions[$region] ?? 'region').')';
            $add($region, $label, 'province');
        }

        foreach ($data['districts'] as [$code, $name, $city]) {
            $add($code, $name, 'district', $city, $city);
        }

        foreach ($data['barangays'] as [$code, $name, $city, $sub]) {
            $add($code, $name, 'barangay', $sub ?? $city, $city);
        }

        return array_values($rows);
    }
}
