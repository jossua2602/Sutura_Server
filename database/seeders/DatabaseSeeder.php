<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SubscriptionPlanSeeder::class,
        ]);

        // The demo seeders create real logins with the password "password" (admin included). They
        // must never run against a live database by accident — production only gets roles and plans.
        // For a defense/demo deployment, set ALLOW_DEMO_SEED=true for that one run (then remove it
        // and change every password).
        if (app()->environment('production') && ! filter_var(env('ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->command?->warn('Production: seeded roles and subscription plans only (demo accounts skipped). Set ALLOW_DEMO_SEED=true to seed the demo shop.');

            return;
        }

        $this->call([
            LocalTestSeeder::class,
            // Demo data for payment methods, requirements, staff and the newer appointment/measurement states.
            StaffAndPaymentsDemoSeeder::class,
            // Sample GCash payments for Admin → Payments (pending / paid / rejected).
            PlatformPaymentsDemoSeeder::class,
            // Deliberately disabled (2026-09-29) — the owner wants the local
            // demo down to a single shop (Thread & Needle Tailoring / Maria
            // Cruz) only, and had the other 4 shops this seeder creates
            // force-deleted from the dev DB. Leave this commented out so a
            // future `db:seed` doesn't silently bring them back; uncomment
            // if the Branch Manager demo account (one of this seeder's
            // stores) is needed again.
            // AdditionalStoresSeeder::class,
        ]);
    }
}
