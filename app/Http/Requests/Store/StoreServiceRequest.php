<?php

namespace App\Http\Requests\Store;

use App\Models\CatalogItem;
use App\Models\Service;
use App\Support\CanonicalTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Branch managers can create/edit services too now — the dashboard
        // sidebar has always shown "Services" to them (isStoreOwner ||
        // isBranchManager in dashboard/layout.tsx), but this coarse check
        // used to only allow store_owner, so the route-level middleware
        // change (routes/api.php) alone wasn't enough — this FormRequest's
        // own authorize() was a second, independent gate blocking them.
        return $this->user()->hasRole('store_owner') || $this->user()->hasRole('branch_manager');
    }

    public function rules(): array
    {
        // 'others' is a deliberate safety-valve service_category (see
        // App\Support\CanonicalTaxonomy) — free text for its leaf type
        // rather than an empty Rule::in() list that would reject everything.
        $serviceLeafTypeRule = $this->input('service_category') === 'others'
            ? ['nullable', 'string', 'max:100']
            : ['nullable', 'string', Rule::in(CanonicalTaxonomy::allServiceTypeSlugs())];

        return [
            ...\App\Support\OrderRequirements::offeringRules(),
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:100'],
            // Same men/women/wedding/office axis as CatalogItem::DEPARTMENTS
            // — distinct from `category`/`categories` above, which stay
            // free-text marketing copy on purpose.
            'department' => ['nullable', 'string', Rule::in(CatalogItem::DEPARTMENTS)],
            'service_types' => ['nullable', 'array'],
            'service_types.*' => [Rule::in(Service::SERVICE_TYPES)],
            // Canonical Services.md taxonomy — additive to service_types
            // above (an unrelated, operational enum). service_leaf_type is
            // only checked against its parent service_category's own leaf
            // list when both are present; either may be set alone.
            'service_category' => ['nullable', 'string', Rule::in(CanonicalTaxonomy::SERVICE_CATEGORIES)],
            // Which "Service Categories" chip it sits under on the shop page.
            // A standard group, or one of the shop's own services by name ("+ Add Service").
            'showcase_category' => ['nullable', 'string', 'max:60', Rule::in(array_merge(
                CanonicalTaxonomy::SERVICE_SHOWCASE_CATEGORIES,
                $this->route('store')?->custom_services ?? [],
            ))],
            'service_leaf_type' => $serviceLeafTypeRule,
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after_or_equal:sale_starts_at'],
            'estimated_days' => ['nullable', 'integer', 'min:1'],
            'estimated_days_max' => ['nullable', 'integer', 'min:1', 'gt:estimated_days'],
            'min_order_qty' => ['nullable', 'integer', 'min:1'],
            'own_fabric_deduction' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'custom_fields' => ['nullable', 'array'],
            'roster_fields' => ['nullable', 'array'],
            'roster_fields.*.id' => ['required_with:roster_fields', 'string'],
            'roster_fields.*.label' => ['required_with:roster_fields', 'string', 'max:60'],
            'roster_fields.*.type' => ['required_with:roster_fields', 'in:text,number,select'],
            'roster_fields.*.options' => ['nullable', 'array'],
            'pricing_tiers' => ['required', 'array', 'min:1'],
            'pricing_tiers.*.label' => ['required', 'string', 'max:100'],
            'pricing_tiers.*.amount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'size_chart_image_url' => ['nullable', 'string', 'max:2048'],
            'size_chart_image_urls' => ['nullable', 'array', 'max:10'],
            'size_chart_image_urls.*' => ['string', 'max:2048'],
            'size_chart_columns' => ['nullable', 'array'],
            'size_chart_columns.*' => ['string', 'max:100'],
            'size_chart_rows' => ['nullable', 'array'],
            'size_chart_rows.*.size' => ['required_with:size_chart_rows', 'string', 'max:100'],
            'size_chart_rows.*.values' => ['nullable', 'array'],
            'size_chart_rows.*.values.*' => ['nullable', 'string', 'max:100'],
        ];
    }
}
