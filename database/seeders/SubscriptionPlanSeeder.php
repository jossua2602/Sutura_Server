<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // firstOrCreate, not updateOrCreate: these are only the starting
        // values. Once a plan exists, the System Admin's Plans page owns it,
        // and re-running db:seed must not undo their edits.

        // ---------------------------------------------------------------
        // BASIC — ₱299/mo
        // ---------------------------------------------------------------
        SubscriptionPlan::firstOrCreate(
            ['slug' => 'basic'],
            [
                'name' => 'Basic',
                'description' => 'Perfect for independent tailors just getting started online.',
                'price_monthly' => 299.00,
                'price_yearly' => 2990.00,
                'max_staff' => 2,
                'max_branches' => 0, // Main shop only (the Main Shop never counts)
                'max_services' => 10,
                'max_appointments_per_month' => 50,
                'features' => [
                    'Customer Management',
                    'Appointment Scheduling',
                    'Order Tracking',
                    'Measurement Recording',
                    'Standard Search Listing',
                ],
                'is_active' => true,
            ]
        );

        // ---------------------------------------------------------------
        // PRO — ₱799/mo
        // ---------------------------------------------------------------
        SubscriptionPlan::firstOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'Pro',
                'description' => 'Sales and staff reports, and text messages to your customers.',
                'price_monthly' => 799.00,
                'price_yearly' => 7990.00,
                'max_staff' => 5,
                'max_branches' => 0, // Main shop only (the Main Shop never counts)
                'max_services' => 50,
                'max_appointments_per_month' => 200,
                'features' => [
                    // Only what the plan really unlocks (see 2026_11_28_120000_keep_only_real_plan_perks).
                    'All Basic Plan Features',
                    'Sales & Staff Reports',
                    'SMS Text Notifications',
                ],
                'is_active' => true,
            ]
        );

        // ---------------------------------------------------------------
        // PREMIUM — ₱1,999/mo
        // ---------------------------------------------------------------
        SubscriptionPlan::firstOrCreate(
            ['slug' => 'premium'],
            [
                'name' => 'Premium',
                'description' => 'Branch shops, top placement in the shop list, branch reports and exports.',
                'price_monthly' => 1999.00,
                'price_yearly' => 19990.00,
                'max_staff' => -1, // Unlimited
                'max_branches' => -1, // Unlimited
                'max_services' => -1, // Unlimited
                'max_appointments_per_month' => -1, // Unlimited
                'features' => [
                    'All Pro Plan Features',
                    'Multi-Branch Management',
                    'Featured Store Visibility (Top Placement)',
                    'Branch Comparison Reports',
                    'Income & Receipt Exports',
                ],
                'is_active' => true,
            ]
        );
    }
}
