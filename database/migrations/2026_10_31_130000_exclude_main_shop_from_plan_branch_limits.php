<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// subscription_plans.max_branches used to count every location, Main Shop
// included (1 = main shop only). It now counts Branch Shops only — the
// locations beyond the Main Shop — so 0 = main shop only. Each plan keeps
// the same real capacity: positive limits drop by one; -1 (unlimited) and
// null (fallback rule) are untouched.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscription_plans')->where('max_branches', '>', 0)->decrement('max_branches');
    }

    public function down(): void
    {
        DB::table('subscription_plans')->where('max_branches', '>=', 0)->increment('max_branches');
    }
};
