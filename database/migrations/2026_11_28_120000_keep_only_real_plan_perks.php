<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The plan cards listed perks that every plan already gets, or that don't
 * exist (text-only profile, boosted search, inquiries, custom branding…).
 * This keeps only what each plan really unlocks. Prices and limits are not
 * touched. "Featured Store Visibility (Top Placement)" keeps its exact text:
 * SubscriptionSwitcher reads it to mark a shop as featured.
 */
return new class extends Migration
{
    private const PERKS = [
        'basic' => [
            'features' => [
                'Customer Management',
                'Appointment Scheduling',
                'Order Tracking',
                'Measurement Recording',
                'Standard Search Listing',
            ],
            'description' => null,
        ],
        'pro' => [
            'features' => [
                'All Basic Plan Features',
                'Sales & Staff Reports',
                'SMS Text Notifications',
            ],
            'description' => 'Sales and staff reports, and text messages to your customers.',
        ],
        'premium' => [
            'features' => [
                'All Pro Plan Features',
                'Multi-Branch Management',
                'Featured Store Visibility (Top Placement)',
                'Branch Comparison Reports',
                'Income & Receipt Exports',
            ],
            'description' => 'Branch shops, top placement in the shop list, branch reports and exports.',
        ],
    ];

    private const OLD = [
        'basic' => [
            'features' => ['Customer Management', 'Appointment Scheduling', 'Order Tracking', 'Measurement Recording', 'Text-Only Profile', 'Manual Updates (Web Portal)', 'Standard Search Listing'],
            'description' => null,
        ],
        'pro' => [
            'features' => ['All Basic Plan Features', 'Boosted Search Visibility', 'Visual Portfolio Gallery', 'Direct Customer Inquiries', 'Visual Dashboard & Analytics', 'Sales & Staff Reports', 'SMS/Email Notifications', 'Measurement History', 'Multi-User Access', 'Staff Management'],
            'description' => 'Grow faster with visibility tools, portfolio, and team management.',
        ],
        'premium' => [
            'features' => ['All Pro Plan Features', 'Multi-Branch Management', 'Custom Branding', 'Featured Store Visibility (Top Placement)', 'Branch Comparison Reports', 'Income & Receipt Exports'],
            'description' => 'Top-tier visibility, custom branding, and advanced reporting for serious stores.',
        ],
    ];

    public function up(): void
    {
        $this->apply(self::PERKS);
    }

    public function down(): void
    {
        $this->apply(self::OLD);
    }

    private function apply(array $plans): void
    {
        foreach ($plans as $slug => $plan) {
            $fields = ['features' => json_encode($plan['features']), 'updated_at' => now()];
            if ($plan['description'] !== null) {
                $fields['description'] = $plan['description'];
            }
            DB::table('subscription_plans')->where('slug', $slug)->update($fields);
        }
    }
};
