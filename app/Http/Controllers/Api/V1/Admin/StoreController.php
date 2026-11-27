<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveStoreRequest;
use App\Models\Store;
use App\Notifications\StoreApplicationStatusNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Store Directory (sutura2's ShopDirectoryView): plan + branch count
        // alongside the owner so the admin sees the whole shop at a glance.
        $query = Store::with(['owner:id,name,email', 'subscription.plan:id,name'])
            ->withCount('branches')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->input('visibility') === 'hidden') {
            $query->where('is_hidden', true);
        } elseif ($request->input('visibility') === 'visible') {
            // "Active": approved shops customers can see (not hidden by an admin or a lapsed plan).
            $query->where('is_hidden', false);
        }
        // Plan filter: the shop's latest subscription is on this plan (basic | pro | premium).
        if (in_array($plan = strtolower((string) $request->input('plan')), ['basic', 'pro', 'premium'], true)) {
            $query->whereHas('subscription.plan', fn ($p) => $p->where('slug', $plan));
        }
        if ($search = trim((string) $request->input('search'))) {
            $term = '%'.strtolower($search).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(city) LIKE ?', [$term])
                ->orWhereHas('owner', fn ($o) => $o->whereRaw('LOWER(email) LIKE ?', [$term])->orWhereRaw('LOWER(name) LIKE ?', [$term])));
        }

        $perPage = $request->input('per_page', 15);
        $stores = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $stores->items(),
            'meta' => [
                'current_page' => $stores->currentPage(),
                'last_page' => $stores->lastPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
            ],
        ]);
    }

    /**
     * One shop's details for the admin Shops page pop-up: profile, owner,
     * plan, every branch (with its verification state), and activity counts.
     * Bank / GCash account numbers are left out; the admin doesn't need them here.
     */
    public function show(Store $store): JsonResponse
    {
        $store->load([
            'owner:id,name,email,phone,last_seen_at,created_at',
            'approvedBy:id,name',
            'subscription.plan:id,name,slug',
            'branches' => fn ($q) => $q->orderByDesc('is_main')->orderBy('name')
                ->select('id', 'store_id', 'name', 'address', 'barangay', 'district', 'city', 'contact_number', 'is_main', 'status', 'verification_status', 'latitude', 'longitude'),
        ])->loadCount(['staff', 'services', 'catalogItems', 'jobOrders', 'appointments', 'customers', 'reviews'])
            ->loadAvg('reviews', 'rating');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'status' => $store->status,
                'is_hidden' => (bool) $store->is_hidden,
                'admin_hidden_at' => $store->admin_hidden_at,
                'admin_hidden_reason' => $store->admin_hidden_reason,
                'logo_path' => $store->logo_path,
                'banner_path' => $store->banner_path,
                'description' => $store->description,
                'business_type' => $store->business_type,
                'address' => $store->address,
                'city' => $store->city,
                'phone' => $store->phone,
                'email' => $store->email,
                'specializations' => $store->specializations ?? [],
                'social_links' => $store->social_links ?? [],
                'operating_hours' => $store->operating_hours,
                'created_at' => $store->created_at,
                'approved_at' => $store->approved_at,
                'approved_by' => $store->approvedBy?->name,
                'owner' => $store->owner,
                'subscription' => $store->subscription,
                'branches' => $store->branches,
                'counts' => [
                    'staff' => $store->staff_count,
                    'services' => $store->services_count,
                    'catalog_items' => $store->catalog_items_count,
                    'job_orders' => $store->job_orders_count,
                    'appointments' => $store->appointments_count,
                    'customers' => $store->customers_count,
                    'reviews' => $store->reviews_count,
                ],
                'rating' => $store->reviews_avg_rating !== null ? round((float) $store->reviews_avg_rating, 1) : null,
            ],
        ]);
    }

    /**
     * Pins for the admin Shops map: one per live branch (active + verified,
     * the same set the public /map shows) of every active shop (approved,
     * visible to customers), with the shop's current plan. A shop with no live
     * pinned branch falls back to its own location so it never drops off the map.
     * Unpaginated on purpose: the map shows them all at once.
     */
    public function map(Request $request): JsonResponse
    {
        $query = Store::query()
            ->select(['id', 'name', 'slug', 'logo_path', 'operating_hours', 'address', 'city', 'province', 'latitude', 'longitude'])
            ->with([
                'subscription.plan:id,name,slug',
                'branches' => fn ($b) => $b->live()
                    ->whereNotNull('latitude')->whereNotNull('longitude')
                    ->select(['id', 'store_id', 'name', 'is_main', 'address', 'barangay', 'district', 'city', 'province', 'latitude', 'longitude'])
                    ->orderByDesc('is_main')->orderBy('name'),
            ])
            ->where('status', 'approved')
            ->where('is_hidden', false);

        if (in_array($plan = strtolower((string) $request->input('plan')), ['basic', 'pro', 'premium'], true)) {
            $query->whereHas('subscription.plan', fn ($p) => $p->where('slug', $plan));
        }
        if ($search = trim((string) $request->input('search'))) {
            $term = '%'.strtolower($search).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(city) LIKE ?', [$term]));
        }

        $pins = $query->orderBy('name')->get()->flatMap(function (Store $s) {
            $shop = [
                'store_id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                // Logo + hours draw the same pin as the public /map (logo avatar, open/closed dot).
                'logo_path' => $s->logo_path,
                'operating_hours' => $s->operating_hours,
                'plan' => $s->subscription?->plan?->name,
                'plan_slug' => $s->subscription?->plan?->slug,
                'subscription_status' => $s->subscription?->status,
            ];

            if ($s->branches->isNotEmpty()) {
                return $s->branches->map(fn ($b) => $shop + [
                    'key' => 'branch-'.$b->id,
                    'branch_name' => $b->name,
                    'is_main' => (bool) $b->is_main,
                    'address' => $b->address,
                    'barangay' => $b->barangay,
                    'district' => $b->district,
                    'city' => $b->city,
                    'province' => $b->province,
                    'latitude' => (float) $b->latitude,
                    'longitude' => (float) $b->longitude,
                ]);
            }
            if ($s->latitude === null || $s->longitude === null) {
                return [];
            }

            return [$shop + [
                'key' => 'store-'.$s->id,
                'branch_name' => null,
                'is_main' => true,
                'address' => $s->address,
                'barangay' => null,
                'district' => null,
                'city' => $s->city,
                'province' => $s->province,
                'latitude' => (float) $s->latitude,
                'longitude' => (float) $s->longitude,
            ]];
        })->values();

        return response()->json(['success' => true, 'data' => $pins]);
    }

    public function approve(ApproveStoreRequest $request, Store $store): JsonResponse
    {
        $store->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);
        // The admin looked at the pinned location while reviewing — the shop's branches go live with it.
        $store->branches()->where('verification_status', 'pending')->update(['verification_status' => 'verified', 'verified_at' => now(), 'verified_by' => $request->user()->id]);

        $store->owner?->notify(new StoreApplicationStatusNotification($store, 'approved'));

        return response()->json([
            'success' => true,
            'message' => 'Store approved successfully.',
            'data' => $store,
        ]);
    }

    public function reject(Request $request, Store $store): JsonResponse
    {
        $request->validate(['rejection_reason' => 'required|string']);

        $store->update([
            'status' => 'rejected',
            'rejection_reason' => $request->rejection_reason,
        ]);

        $store->owner?->notify(new StoreApplicationStatusNotification($store, 'rejected'));

        return response()->json([
            'success' => true,
            'message' => 'Store rejected.',
            'data' => $store,
        ]);
    }
}
