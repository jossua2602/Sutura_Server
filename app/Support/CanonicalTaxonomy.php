<?php

namespace App\Support;

/**
 * The single source of truth for SUTURA's apparel + services taxonomy,
 * transcribed directly from Mocked Layout/Categories.md and Services.md
 * (the canonical "Apparel & Services Directory" the header nav, /search,
 * and the catalog/service forms all key off). Mirrored exactly on the
 * frontend at src/lib/canonicalTaxonomy.ts — keep both in sync by hand,
 * same convention this codebase already uses for catalogCategories.ts
 * mirroring CatalogItem's old constants.
 *
 * Shape: DEPARTMENTS[department]['subcategories'][subcategory]['structures'][structure] = string[] of garment_type slugs.
 * Children's Apparel (per Categories.md) has no defined leaf garment types
 * yet — its structures exist (top_wear/bottom_wear/sets) but their arrays
 * are deliberately empty, not invented data.
 */
class CanonicalTaxonomy
{
    public const DEPARTMENTS = ['men', 'women', 'children'];

    public const DEPARTMENT_LABELS = [
        'men' => "Men's Apparel",
        'women' => "Women's Apparel",
        'children' => "Children's Apparel",
    ];

    // 'other' is a deliberate safety valve, not a doc-defined structure —
    // every department's subcategory list ends in an "Others" entry (see
    // tree()) so a garment that doesn't fit the canonical hierarchy still
    // has somewhere to go under the right department, rather than an owner
    // being unable to list it at all.
    public const GARMENT_STRUCTURES = ['top_wear', 'bottom_wear', 'sets', 'one_piece', 'other'];

    public const GARMENT_STRUCTURE_LABELS = [
        'top_wear' => 'Top Wear',
        'bottom_wear' => 'Bottom Wear',
        'sets' => 'Sets',
        'one_piece' => 'One-Piece',
        'other' => 'Other',
    ];

    public const SUBCATEGORY_LABELS = [
        'formal_wear' => 'Formal Wear',
        'traditional_wear' => 'Traditional Wear',
        'traditional_cultural_wear' => 'Traditional & Cultural Wear',
        'dresses_gowns' => 'Dresses & Gowns',
        'casual_wear' => 'Casual Wear',
        'uniforms_workwear' => 'Uniforms & Workwear',
        'sportswear_teamwear' => 'Sportswear & Teamwear',
        'costumes_performance' => 'Costumes & Performance',
        'outerwear' => 'Outerwear',
        'boys_apparel' => "Boys' Apparel",
        'girls_apparel' => "Girls' Apparel",
        'others' => 'Others',
    ];

    public const GARMENT_TYPE_LABELS = [
        // Men — Formal Wear
        'dress_shirts' => 'Dress Shirts', 'formal_shirts' => 'Formal Shirts', 'formal_vests' => 'Formal Vests',
        'formal_trousers' => 'Formal Trousers', 'dress_pants' => 'Dress Pants', 'pleated_trousers' => 'Pleated Trousers',
        'suits' => 'Suits', 'tuxedo_sets' => 'Tuxedo Sets', 'formal_sets' => 'Formal Sets',
        // Men — Traditional Wear
        'barong_tagalog' => 'Barong Tagalog', 'modern_barong' => 'Modern Barong', 'short_sleeve_barong' => 'Short-Sleeve Barong',
        'other_traditional_tops' => 'Other Traditional Tops', 'traditional_trousers' => 'Traditional Trousers',
        'traditional_pants' => 'Traditional Pants', 'barong_sets' => 'Barong Sets', 'traditional_wear_sets' => 'Traditional Wear Sets',
        // Men — Casual Wear
        'polo_shirts' => 'Polo Shirts', 'casual_shirts' => 'Casual Shirts', 'button_down_shirts' => 'Button-Down Shirts',
        'custom_t_shirts' => 'Custom T-Shirts', 'casual_trousers' => 'Casual Trousers', 'chinos' => 'Chinos',
        'casual_pants' => 'Casual Pants', 'casual_sets' => 'Casual Sets',
        // Men/Women — Uniforms & Workwear
        'school_uniform_tops' => 'School Uniform Tops', 'corporate_shirts' => 'Corporate Shirts',
        'office_uniform_tops' => 'Office Uniform Tops', 'hospitality_tops' => 'Hospitality Tops', 'medical_tops' => 'Medical Tops',
        'school_uniform_pants' => 'School Uniform Pants', 'corporate_trousers' => 'Corporate Trousers',
        'office_uniform_pants' => 'Office Uniform Pants', 'school_uniform_sets' => 'School Uniform Sets',
        'corporate_uniform_sets' => 'Corporate Uniform Sets', 'institutional_uniform_sets' => 'Institutional Uniform Sets',
        // Men/Women — Sportswear & Teamwear
        'basketball_jerseys' => 'Basketball Jerseys', 'volleyball_jerseys' => 'Volleyball Jerseys',
        'esports_jerseys' => 'Esports Jerseys', 'cycling_jerseys' => 'Cycling Jerseys',
        'basketball_shorts' => 'Basketball Shorts', 'volleyball_shorts' => 'Volleyball Shorts', 'team_shorts' => 'Team Shorts',
        'basketball_sets' => 'Basketball Sets', 'volleyball_sets' => 'Volleyball Sets', 'esports_sets' => 'Esports Sets',
        'custom_team_sets' => 'Custom Team Sets',
        // Men — Costumes & Performance
        'costume_tops' => 'Costume Tops', 'character_tops' => 'Character Tops', 'stage_tops' => 'Stage Tops',
        'costume_pants' => 'Costume Pants', 'character_bottoms' => 'Character Bottoms', 'stage_bottoms' => 'Stage Bottoms',
        'full_costumes' => 'Full Costumes', 'stage_costumes' => 'Stage Costumes', 'cosplay_sets' => 'Cosplay Sets',
        // Men — Outerwear
        'jackets' => 'Jackets', 'coats' => 'Coats', 'overshirts' => 'Overshirts', 'lightweight_outerwear' => 'Lightweight Outerwear',
        // Women — Formal Wear
        'formal_blouses' => 'Formal Blouses', 'formal_tops' => 'Formal Tops', 'formal_boleros' => 'Formal Boleros',
        'formal_skirts' => 'Formal Skirts', 'formal_pants' => 'Formal Pants', 'tailored_trousers' => 'Tailored Trousers',
        'womens_suits' => "Women's Suits",
        // Women — Traditional & Cultural Wear
        'filipiniana_tops' => 'Filipiniana Tops', 'terno_tops' => 'Terno Tops', 'baro' => 'Baro',
        'maria_clara_tops' => 'Maria Clara Tops', 'modern_filipiniana_tops' => 'Modern Filipiniana Tops',
        'saya' => 'Saya', 'traditional_skirts' => 'Traditional Skirts',
        'filipiniana_sets' => 'Filipiniana Sets', 'terno_sets' => 'Terno Sets', 'barot_saya_sets' => "Baro't Saya Sets",
        // Women — Dresses & Gowns
        'casual_dresses' => 'Casual Dresses', 'formal_dresses' => 'Formal Dresses', 'evening_dresses' => 'Evening Dresses',
        'debut_gowns' => 'Debut Gowns', 'custom_gowns' => 'Custom Gowns',
        // Women — Casual Wear
        'casual_blouses' => 'Casual Blouses', 'casual_tops' => 'Casual Tops', 'polo_blouses' => 'Polo Blouses',
        'casual_skirts' => 'Casual Skirts', 'trousers' => 'Trousers',
        // Women — Uniforms & Workwear
        'corporate_blouses' => 'Corporate Blouses', 'institutional_tops' => 'Institutional Tops',
        'blazers' => 'Blazers',
        'school_uniform_skirts' => 'School Uniform Skirts', 'corporate_skirts' => 'Corporate Skirts',
    ];

    // 'others' is the same deliberate safety valve as garment structure
    // 'other' above — not in Services.md, added so a service that doesn't
    // fit any defined category still has somewhere to go.
    public const SERVICE_CATEGORIES = [
        'custom_tailoring', 'alterations_repairs', 'uniform_production', 'printing_sublimation', 'custom_costume_creation', 'others',
    ];

    public const SERVICE_CATEGORY_LABELS = [
        'custom_tailoring' => 'Custom Tailoring',
        'alterations_repairs' => 'Alterations & Repairs',
        'uniform_production' => 'Uniform Production',
        'printing_sublimation' => 'Printing & Sublimation',
        'custom_costume_creation' => 'Custom Costume Creation',
        'others' => 'Others',
    ];

    public const SERVICE_TYPE_LABELS = [
        'bespoke_tailoring' => 'Bespoke Tailoring', 'made_to_measure' => 'Made-to-Measure',
        'custom_pattern_making' => 'Custom Pattern Making', 'formal_wear_tailoring' => 'Formal Wear Tailoring',
        'traditional_wear_tailoring' => 'Traditional Wear Tailoring', 'custom_garment_construction' => 'Custom Garment Construction',
        'hemming_length_adjustment' => 'Hemming & Length Adjustment', 'waist_seat_adjustment' => 'Waist & Seat Adjustment',
        'tapering_resizing' => 'Tapering & Resizing', 'sleeve_adjustment' => 'Sleeve Adjustment',
        'zipper_replacement' => 'Zipper Replacement', 'button_fastener_replacement' => 'Button & Fastener Replacement',
        'garment_repair_restoration' => 'Garment Repair & Restoration',
        'school_uniforms' => 'School Uniforms', 'office_uniforms' => 'Office / Corporate Uniforms',
        'medical_uniforms' => 'Medical Uniforms', 'maritime_uniforms' => 'Maritime Uniforms',
        'police_uniforms' => 'PNP / Police Uniforms', 'military_uniforms' => 'AFP / Military Uniforms',
        'fire_uniforms' => 'BFP / Fire Uniforms', 'security_uniforms' => 'Security Guard Uniforms',
        'hospitality_uniforms' => 'Hospitality Uniforms', 'other_uniforms' => 'Other Uniforms',
        'sports_jersey_printing' => 'Sports Jersey Printing', 'full_sublimation' => 'Full Sublimation',
        'teamwear_printing' => 'Teamwear Printing', 'corporate_apparel_printing' => 'Corporate Apparel Printing',
        'custom_apparel_printing' => 'Custom Apparel Printing',
        'cosplay_costumes' => 'Cosplay Costumes', 'stage_theater_costumes' => 'Stage & Theater Costumes',
        'event_costumes' => 'Event Costumes', 'character_costumes' => 'Character Costumes',
        'cultural_dance_costumes' => 'Cultural & Dance Costumes',
    ];

    /**
     * The services a shop offers: the 10 standard ones (STORE_SERVICE_TAGS). Men / Women / Kids
     * were dropped as shop tags on 2026-11-14 (they describe who a shop serves, not a service);
     * a shop's own extra services live in stores.custom_services.
     */
    public static function storeSpecializations(): array
    {
        return self::STORE_SERVICE_TAGS;
    }

    /**
     * The "services offered" a shop ticks at registration / in its profile —
     * modelled on how Davao shops list them on their own signboards
     * (Formal, Casual, Office, School, Maritime, Medical, Sports, Embroidery),
     * plus the two core tailoring services. A store tag only: separate from
     * SERVICE_CATEGORIES, which classifies individual services and packages.
     */
    public const STORE_SERVICE_TAGS = [
        'custom_tailoring', 'alterations_repairs', 'formal', 'casual', 'uniform', 'sports', 'embroidery',
    ];

    /**
     * Uniform types (2026-11-14): Office / School / Medical / Maritime were merged into one
     * "Uniform" service; the kind of uniform is now the service's type (service_leaf_type),
     * which customers filter by under Uniform.
     */
    public const UNIFORM_TYPES = [
        'school_uniforms', 'office_uniforms', 'medical_uniforms', 'maritime_uniforms',
        'police_uniforms', 'military_uniforms', 'fire_uniforms', 'security_uniforms',
        'hospitality_uniforms', 'other_uniforms',
    ];

    /**
     * Tags no longer offered but still on shops that saved them earlier.
     * Accepted on save so those shops can still update their profile.
     */
    public const LEGACY_STORE_SERVICE_TAGS = [
        'office', 'school', 'maritime', 'medical',
        'uniform_production', 'printing_sublimation', 'custom_costume_creation', 'others',
    ];

    /**
     * How a shop groups its own services on its storefront's Services tab
     * ("Service Categories" chips: Uniform, Casual, Office…). Set per service
     * by the owner (services.showcase_category); a service's catalog designs
     * are browsed from inside it. Separate from SERVICE_CATEGORIES, which
     * classifies services for search and job-order handling.
     */
    public const SERVICE_SHOWCASE_CATEGORIES = [
        'uniform', 'casual', 'formal', 'sports', 'embroidery',
        'custom_tailoring', 'alterations_repairs',
    ];

    /** What validation accepts for a store's specializations. */
    public static function selectableStoreSpecializations(): array
    {
        return [...self::storeSpecializations(), ...self::LEGACY_STORE_SERVICE_TAGS];
    }

    /** department => [ subcategory => [ structure => garment_type[] ] ] */
    public static function tree(): array
    {
        return [
            'men' => [
                'formal_wear' => [
                    'top_wear' => ['dress_shirts', 'formal_shirts', 'formal_vests'],
                    'bottom_wear' => ['formal_trousers', 'dress_pants', 'pleated_trousers'],
                    'sets' => ['suits', 'tuxedo_sets', 'formal_sets'],
                    'other' => [],
                ],
                'traditional_wear' => [
                    'top_wear' => ['barong_tagalog', 'modern_barong', 'short_sleeve_barong', 'other_traditional_tops'],
                    'bottom_wear' => ['traditional_trousers', 'traditional_pants'],
                    'sets' => ['barong_sets', 'traditional_wear_sets'],
                    'other' => [],
                ],
                'casual_wear' => [
                    'top_wear' => ['polo_shirts', 'casual_shirts', 'button_down_shirts', 'custom_t_shirts'],
                    'bottom_wear' => ['casual_trousers', 'chinos', 'casual_pants'],
                    'sets' => ['casual_sets'],
                    'other' => [],
                ],
                'uniforms_workwear' => [
                    'top_wear' => ['school_uniform_tops', 'corporate_shirts', 'office_uniform_tops', 'hospitality_tops', 'medical_tops'],
                    'bottom_wear' => ['school_uniform_pants', 'corporate_trousers', 'office_uniform_pants'],
                    'sets' => ['school_uniform_sets', 'corporate_uniform_sets', 'institutional_uniform_sets'],
                    'other' => [],
                ],
                'sportswear_teamwear' => [
                    'top_wear' => ['basketball_jerseys', 'volleyball_jerseys', 'esports_jerseys', 'cycling_jerseys'],
                    'bottom_wear' => ['basketball_shorts', 'volleyball_shorts', 'team_shorts'],
                    'sets' => ['basketball_sets', 'volleyball_sets', 'esports_sets', 'custom_team_sets'],
                    'other' => [],
                ],
                'costumes_performance' => [
                    'top_wear' => ['costume_tops', 'character_tops', 'stage_tops'],
                    'bottom_wear' => ['costume_pants', 'character_bottoms', 'stage_bottoms'],
                    'sets' => ['full_costumes', 'stage_costumes', 'cosplay_sets'],
                    'other' => [],
                ],
                'outerwear' => [
                    'top_wear' => ['jackets', 'coats', 'overshirts', 'lightweight_outerwear'],
                    'other' => [],
                ],
                // Safety valve — not in Categories.md, deliberately added so
                // an item that doesn't fit any defined subcategory still has
                // somewhere to go under Men rather than nowhere at all.
                'others' => ['other' => []],
            ],
            'women' => [
                'formal_wear' => [
                    'top_wear' => ['formal_blouses', 'formal_tops', 'formal_boleros'],
                    'bottom_wear' => ['formal_skirts', 'formal_pants', 'tailored_trousers'],
                    'sets' => ['womens_suits', 'formal_sets'],
                    'other' => [],
                ],
                'traditional_cultural_wear' => [
                    'top_wear' => ['filipiniana_tops', 'terno_tops', 'baro', 'maria_clara_tops', 'modern_filipiniana_tops'],
                    'bottom_wear' => ['saya', 'traditional_skirts', 'traditional_pants'],
                    'sets' => ['filipiniana_sets', 'terno_sets', 'barot_saya_sets'],
                    'other' => [],
                ],
                'dresses_gowns' => [
                    'one_piece' => ['casual_dresses', 'formal_dresses', 'evening_dresses', 'debut_gowns', 'custom_gowns'],
                    'other' => [],
                ],
                'casual_wear' => [
                    'top_wear' => ['casual_blouses', 'casual_tops', 'polo_blouses'],
                    'bottom_wear' => ['casual_skirts', 'casual_pants', 'trousers'],
                    'sets' => ['casual_sets'],
                    'other' => [],
                ],
                'uniforms_workwear' => [
                    'top_wear' => ['school_uniform_tops', 'corporate_blouses', 'office_uniform_tops', 'institutional_tops', 'blazers'],
                    'bottom_wear' => ['school_uniform_skirts', 'corporate_skirts', 'office_uniform_pants'],
                    'sets' => ['school_uniform_sets', 'corporate_uniform_sets', 'institutional_uniform_sets'],
                    'other' => [],
                ],
                'sportswear_teamwear' => [
                    'top_wear' => ['basketball_jerseys', 'volleyball_jerseys', 'esports_jerseys', 'cycling_jerseys'],
                    'bottom_wear' => ['basketball_shorts', 'volleyball_shorts', 'team_shorts'],
                    'sets' => ['custom_team_sets'],
                    'other' => [],
                ],
                // Costumes & Performance is defined in Categories.md with no
                // leaf garment types yet, same gap as Children's Apparel.
                'costumes_performance' => [
                    'top_wear' => [], 'bottom_wear' => [], 'sets' => [], 'other' => [],
                ],
                'others' => ['other' => []],
            ],
            'children' => [
                // Boys'/Girls' Apparel have no leaf garment types defined in
                // Categories.md — structure exists, leaf lists intentionally
                // empty rather than invented.
                'boys_apparel' => ['top_wear' => [], 'bottom_wear' => [], 'sets' => [], 'other' => []],
                'girls_apparel' => ['top_wear' => [], 'bottom_wear' => [], 'sets' => [], 'other' => []],
                'others' => ['other' => []],
            ],
        ];
    }

    /** service_category => service_type[] */
    public static function servicesTree(): array
    {
        return [
            'custom_tailoring' => [
                'bespoke_tailoring', 'made_to_measure', 'custom_pattern_making',
                'formal_wear_tailoring', 'traditional_wear_tailoring', 'custom_garment_construction',
            ],
            'alterations_repairs' => [
                'hemming_length_adjustment', 'waist_seat_adjustment', 'tapering_resizing',
                'sleeve_adjustment', 'zipper_replacement', 'button_fastener_replacement', 'garment_repair_restoration',
            ],
            'uniform_production' => self::UNIFORM_TYPES,
            'printing_sublimation' => [
                'sports_jersey_printing', 'full_sublimation', 'teamwear_printing',
                'corporate_apparel_printing', 'custom_apparel_printing',
            ],
            'custom_costume_creation' => [
                'cosplay_costumes', 'stage_theater_costumes', 'event_costumes',
                'character_costumes', 'cultural_dance_costumes',
            ],
            'others' => [],
        ];
    }

    public static function subcategoriesFor(string $department): array
    {
        return array_keys(self::tree()[$department] ?? []);
    }

    public static function structuresFor(string $department, string $subcategory): array
    {
        return array_keys(self::tree()[$department][$subcategory] ?? []);
    }

    public static function garmentTypesFor(string $department, string $subcategory, string $structure): array
    {
        return self::tree()[$department][$subcategory][$structure] ?? [];
    }

    /** Flat, deduped list of every valid garment_type slug — for Rule::in(). */
    public static function allGarmentTypeSlugs(): array
    {
        $slugs = [];
        foreach (self::tree() as $subcats) {
            foreach ($subcats as $structures) {
                foreach ($structures as $types) {
                    $slugs = array_merge($slugs, $types);
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    public static function allSubcategorySlugs(): array
    {
        $slugs = [];
        foreach (self::tree() as $subcats) {
            $slugs = array_merge($slugs, array_keys($subcats));
        }

        return array_values(array_unique($slugs));
    }

    public static function allServiceTypeSlugs(): array
    {
        $slugs = [];
        foreach (self::servicesTree() as $types) {
            $slugs = array_merge($slugs, $types);
        }

        return array_values(array_unique($slugs));
    }
}
