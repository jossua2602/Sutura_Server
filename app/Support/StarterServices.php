<?php

namespace App\Support;

use App\Models\Service;
use App\Models\Store;

/**
 * Turns what a shop ticked at registration ("Specializes in") into draft services, so the
 * owner only has to add the price, estimate and description instead of retyping them.
 * Drafts start hidden (is_active false, no price) — customers never see an unpriced service.
 */
class StarterServices
{
    /** store tag => [name, service_category, showcase_category, service_types] */
    private const FROM_TAGS = [
        'custom_tailoring' => ['Custom Tailoring', 'custom_tailoring', 'custom_tailoring', [Service::TYPE_CUSTOM_TAILORING]],
        'alterations_repairs' => ['Alterations & Repairs', 'alterations_repairs', 'alterations_repairs', [Service::TYPE_ALTERATION_REPAIR]],
        'formal' => ['Formal Wear', 'custom_tailoring', 'formal', [Service::TYPE_CUSTOM_TAILORING]],
        'casual' => ['Casual Wear', 'custom_tailoring', 'casual', [Service::TYPE_CUSTOM_TAILORING]],
        'office' => ['Office Uniforms', 'uniform_production', 'office', [Service::TYPE_CUSTOM_TAILORING]],
        'school' => ['School Uniforms', 'uniform_production', 'school', [Service::TYPE_CUSTOM_TAILORING]],
        'maritime' => ['Maritime Uniforms', 'uniform_production', 'maritime', [Service::TYPE_CUSTOM_TAILORING]],
        'medical' => ['Medical Uniforms', 'uniform_production', 'medical', [Service::TYPE_CUSTOM_TAILORING]],
        'sports' => ['Sportswear', 'printing_sublimation', 'sports', [Service::TYPE_BULK_SUBLIMATION]],
        'embroidery' => ['Embroidery', 'others', 'embroidery', []],
        // Older tags some shops still carry.
        'uniform_production' => ['Uniform Production', 'uniform_production', 'uniform', [Service::TYPE_CUSTOM_TAILORING]],
        'printing_sublimation' => ['Printing & Sublimation', 'printing_sublimation', null, [Service::TYPE_BULK_SUBLIMATION]],
        'custom_costume_creation' => ['Custom Costume Creation', 'custom_costume_creation', null, [Service::TYPE_CUSTOM_TAILORING]],
    ];

    /** Creates the missing drafts and returns how many were added. Safe to run twice. */
    public static function createFor(Store $store): int
    {
        $drafts = [];
        // Men / Women / Kids say who the shop serves, not a service, so they have no entry above.
        foreach ($store->specializations ?? [] as $tag) {
            if (isset(self::FROM_TAGS[$tag])) {
                [$name, $category, $showcase, $types] = self::FROM_TAGS[$tag];
                $drafts[] = compact('name', 'category', 'showcase', 'types');
            }
        }
        foreach ($store->custom_services ?? [] as $name) {
            $name = mb_substr(trim((string) $name), 0, 191);
            if ($name !== '') {
                $drafts[] = ['name' => $name, 'category' => 'others', 'showcase' => null, 'types' => []];
            }
        }

        // Never duplicate a service the shop already has (by name, any case, deleted ones too).
        $taken = Service::withTrashed()->where('store_id', $store->id)->pluck('name')
            ->map(fn ($n) => mb_strtolower(trim($n)))->flip();

        $added = 0;
        foreach ($drafts as $draft) {
            $key = mb_strtolower($draft['name']);
            if ($taken->has($key)) {
                continue;
            }
            $taken->put($key, true);

            // No price line yet: the edit form builds one from `tags`, and the owner adds its amount.
            $store->services()->create([
                'name' => $draft['name'],
                'service_category' => $draft['category'],
                'showcase_category' => $draft['showcase'],
                'service_types' => $draft['types'],
                'service_type' => $draft['types'][0] ?? null,
                'base_price' => null,
                'estimated_days' => null,
                'tags' => [$draft['name']],
                'is_active' => false,
            ]);
            $added++;
        }

        return $added;
    }
}
