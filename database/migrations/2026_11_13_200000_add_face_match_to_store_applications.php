<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The on-device check comparing the face in the selfie with the photo on the ID it holds.
 * A hint for the review team only (computed on the applicant's device, so never trusted
 * to approve anything). The face data itself never leaves the device; only this result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_applications', function (Blueprint $table) {
            // likely | unsure | no_match | no_id_face | not_checked; null = sent before this check existed.
            $table->string('face_match', 20)->nullable()->after('selfie_with_id_path');
            // Distance between the two faces (lower = more alike; about 0.6 is the usual cut-off).
            $table->decimal('face_match_distance', 4, 3)->nullable()->after('face_match');
        });
    }

    public function down(): void
    {
        Schema::table('store_applications', function (Blueprint $table) {
            $table->dropColumn(['face_match', 'face_match_distance']);
        });
    }
};
