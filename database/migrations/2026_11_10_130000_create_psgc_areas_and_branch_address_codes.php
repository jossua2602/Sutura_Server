<?php

use App\Console\Commands\ImportPsgc;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

// Structured shop address (PSA PSGC): the Province / City-Municipality /
// District / Barangay lists, and the chosen codes + names on each branch
// (the main branch carries the shop's own address). Codes keep the data
// consistent even if a name is spelled differently later.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('psgc_areas', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name', 191);
            $table->string('level', 10);                 // province | city | district | barangay
            $table->string('parent_code', 10)->nullable();
            $table->string('city_code', 10)->nullable(); // districts + barangays: their city/municipality
            $table->index(['level', 'parent_code']);
            $table->index('city_code');
        });

        Schema::table('store_branches', function (Blueprint $table) {
            $table->string('street', 255)->nullable()->after('address');
            $table->string('province', 191)->nullable()->after('city');
            $table->string('province_code', 10)->nullable()->after('province');
            $table->string('city_code', 10)->nullable()->after('province_code');
            $table->string('district_code', 10)->nullable()->after('city_code');
            $table->string('barangay_code', 10)->nullable()->after('district_code');
            $table->index('province_code');
            $table->index('city_code');
            $table->index('barangay_code');
        });

        // Load the repo copy so the pickers work right after `migrate`.
        if (is_file(ImportPsgc::dataPath())) {
            Artisan::call('app:import-psgc');
        }
    }

    public function down(): void
    {
        Schema::table('store_branches', function (Blueprint $table) {
            $table->dropIndex(['province_code']);
            $table->dropIndex(['city_code']);
            $table->dropIndex(['barangay_code']);
            $table->dropColumn(['street', 'province', 'province_code', 'city_code', 'district_code', 'barangay_code']);
        });
        Schema::dropIfExists('psgc_areas');
    }
};
