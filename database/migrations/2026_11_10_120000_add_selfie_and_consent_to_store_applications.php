<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Shop application: a live selfie holding the government ID (checked by the
// review team), plus proof of the privacy consent (Documents step) and the
// terms agreement (last step): when each was given and which notice version.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_applications', function (Blueprint $table) {
            $table->text('selfie_with_id_path')->nullable()->after('government_id_path');
            // Set when the retention job deletes the selfie (30 days after the decision).
            $table->timestamp('selfie_deleted_at')->nullable()->after('selfie_with_id_path');
            $table->timestamp('privacy_consent_at')->nullable()->after('previous_documents');
            $table->timestamp('terms_accepted_at')->nullable()->after('privacy_consent_at');
            $table->string('consent_version', 20)->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('store_applications', function (Blueprint $table) {
            $table->dropColumn(['selfie_with_id_path', 'selfie_deleted_at', 'privacy_consent_at', 'terms_accepted_at', 'consent_version']);
        });
    }
};
