<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When the owner re-uploads a document through a change-request link, the
// file it replaces is kept so the admin can compare Before / After.
// Shape: {"dti": {"path": "...", "uploaded_at": "...", "replaced_at": "..."}, "permit-0": {...}}
// Only the latest replaced version per document is kept.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_applications', function (Blueprint $table) {
            $table->json('previous_documents')->nullable()->after('change_history');
        });
    }

    public function down(): void
    {
        Schema::table('store_applications', function (Blueprint $table) {
            $table->dropColumn('previous_documents');
        });
    }
};
