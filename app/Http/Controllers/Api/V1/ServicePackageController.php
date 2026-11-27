<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServicePackageController extends Controller
{
    public function index(Store $store): JsonResponse
    {
        $packages = $store->servicePackages()->with('services')
            ->withCount('jobOrders')
            ->withSum('jobOrders as job_revenue', 'total_amount')
            ->withSum('jobOrders as job_balance_sum', 'balance')
            ->withSum('jobOrders as job_discount_sum', 'discount_amount')
            ->get();
        // Same discount-aware formula as services and designs.
        $packages->each(fn ($p) => $p->total_revenue = (float) $p->job_revenue - (float) $p->job_balance_sum - (float) $p->job_discount_sum);

        return response()->json([
            'success' => true,
            'data' => $packages,
        ]);
    }

    /**
     * Publicly accessible list of a store's active packages for its storefront page.
     */
    public function publicIndex(Store $store): JsonResponse
    {
        $packages = $store->servicePackages()
            ->where('is_active', true)
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->with('services:id,name,base_price')
            ->get(['id', 'store_id', 'name', 'description', 'service_category', 'image_url', 'bundle_price',
                'measurement_requirement', 'fitting_requirement', 'payment_policy', 'payment_policy_percent'])
            ->each(function (ServicePackage $package) use ($store) {
                $package->reviews_avg_rating = $package->reviews_avg_rating !== null
                    ? round((float) $package->reviews_avg_rating, 1)
                    : null;
                // What ordering the set asks for (payment up front, fitting…), for the order form.
                $package->order_requirements = \App\Support\OrderRequirements::resolve($store, [$package]);
            });

        return response()->json([
            'success' => true,
            'data' => $packages,
        ]);
    }

    /**
     * Cross-store combo packages for /search (Services tab), filtered like services:
     * text, service_category, district.
     */
    public function publicShowroom(Request $request): JsonResponse
    {
        $query = ServicePackage::query()
            ->where('is_active', true)
            ->whereHas('store', fn ($q) => $q->where('is_hidden', false)->where('status', 'approved'))
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->with([
                'services:id,name,base_price',
                'store' => fn ($q) => $q->select('id', 'name', 'slug')->with(['branches' => fn ($b) => $b->live()->select('id', 'store_id', 'district', 'city')]),
            ]);

        if ($request->filled('q')) {
            $search = '%'.strtolower((string) $request->string('q')).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$search])->orWhereRaw('LOWER(description) LIKE ?', [$search])
                ->orWhereHas('services', fn ($s) => $s->whereRaw('LOWER(services.name) LIKE ?', [$search])));
        }
        if ($request->filled('service_category')) {
            $query->whereRaw('LOWER(service_category) = ?', [strtolower((string) $request->string('service_category'))]);
        }
        if ($request->filled('district')) {
            $district = $request->string('district')->toString();
            $query->whereHas('store.branches', fn ($b) => $b->where('district', $district)->live());
        }
        \App\Support\Psgc::applyLocationFilter($query, $request, 'store.branches');

        $packages = $query->latest()->limit(24)->get(['id', 'store_id', 'name', 'description', 'service_category', 'image_url', 'bundle_price'])
            ->each(function (ServicePackage $p) {
                $p->reviews_avg_rating = $p->reviews_avg_rating !== null ? round((float) $p->reviews_avg_rating, 1) : null;
            });

        return response()->json(['success' => true, 'data' => $packages]);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        $validated = $this->validatePackage($request, $store);

        $package = $store->servicePackages()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'service_category' => $validated['service_category'] ?? null,
            'image_url' => $validated['image_url'] ?? null,
            'bundle_price' => $validated['bundle_price'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'measurement_requirement' => $validated['measurement_requirement'] ?? null,
            'fitting_requirement' => $validated['fitting_requirement'] ?? null,
            'payment_policy' => $validated['payment_policy'] ?? null,
            'payment_policy_percent' => $validated['payment_policy_percent'] ?? null,
        ]);

        $package->services()->sync($validated['service_ids']);

        return response()->json([
            'success' => true,
            'data' => $package->load('services'),
        ], 201);
    }

    public function update(Request $request, Store $store, ServicePackage $servicePackage): JsonResponse
    {
        if ($servicePackage->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => "This package isn't part of your shop."], 403);
        }

        $validated = $this->validatePackage($request, $store);

        $servicePackage->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'service_category' => $validated['service_category'] ?? null,
            'image_url' => $validated['image_url'] ?? null,
            'bundle_price' => $validated['bundle_price'] ?? null,
            'is_active' => $validated['is_active'] ?? $servicePackage->is_active,
            'measurement_requirement' => array_key_exists('measurement_requirement', $validated) ? $validated['measurement_requirement'] : $servicePackage->measurement_requirement,
            'fitting_requirement' => array_key_exists('fitting_requirement', $validated) ? $validated['fitting_requirement'] : $servicePackage->fitting_requirement,
            'payment_policy' => array_key_exists('payment_policy', $validated) ? $validated['payment_policy'] : $servicePackage->payment_policy,
            'payment_policy_percent' => array_key_exists('payment_policy_percent', $validated) ? $validated['payment_policy_percent'] : $servicePackage->payment_policy_percent,
        ]);

        $servicePackage->services()->sync($validated['service_ids']);

        return response()->json([
            'success' => true,
            'data' => $servicePackage->fresh('services'),
        ]);
    }

    public function destroy(Store $store, ServicePackage $servicePackage): JsonResponse
    {
        if ($servicePackage->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => "This package isn't part of your shop."], 403);
        }

        $servicePackage->delete();

        return response()->json(['success' => true]);
    }

    /**
     * A package only makes sense as a bundle of 2+ real services belonging to
     * this store — a single-service "package" is just that service.
     */
    private function validatePackage(Request $request, Store $store): array
    {
        return $request->validate([
            ...\App\Support\OrderRequirements::offeringRules(),
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string', 'max:255'],
            // Same canonical list as services, so a combo is searched and filtered like one.
            'service_category' => ['nullable', 'string', Rule::in(\App\Support\CanonicalTaxonomy::SERVICE_CATEGORIES)],
            'bundle_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            // 'distinct' belongs on the wildcard item, not the parent array
            // — Laravel's own rule only actually inspects duplicates that
            // way. Without it, [2, 2] passed the 'min:2' count check while
            // only ever bundling one real service (sync() silently dedupes
            // the pivot), defeating the "2+ services" requirement entirely.
            'service_ids' => ['required', 'array', 'min:2'],
            'service_ids.*' => ['integer', 'distinct', Rule::exists('services', 'id')->where('store_id', $store->id)],
        ]);
    }
}
