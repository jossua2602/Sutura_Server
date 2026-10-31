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
                    'Text-Only Profile',
                    'Manual Updates (Web Portal)',
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
                'description' => 'Grow faster with visibility tools, portfolio, and team management.',
                'price_monthly' => 799.00,
                'price_yearly' => 7990.00,
                'max_staff' => 5,
                'max_branches' => 0, // Main shop only (the Main Shop never counts)
                'max_services' => 50,
                'max_appointments_per_month' => 200,
                'features' => [
                    'All Basic Plan Features',
                    'Boosted Search Visibility',
                    'Visual Portfolio Gallery',
                    'Direct Customer Inquiries',
                    'Visual Dashboard & Analytics',
                    'Sales & Staff Reports',
                    'SMS/Email Notifications',
                    'Measurement History',
                    'Multi-User Access',
                    'Staff Management',
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
                'description' => 'Top-tier visibility, custom branding, and advanced reporting for serious stores.',
                'price_monthly' => 1999.00,
                'price_yearly' => 19990.00,
                'max_staff' => -1, // Unlimited
                'max_branches' => -1, // Unlimited
                'max_services' => -1, // Unlimited
                'max_appointments_per_month' => -1, // Unlimited
                'features' => [
                    'All Pro Plan Features',
                    'Multi-Branch Management',
                    'Custom Branding',
                    'Featured Store Visibility (Top Placement)',
                    'Branch Comparison Reports',
                    'Income & Receipt Exports',
                ],
                'is_active' => true,
            ]
        );
    }
}
