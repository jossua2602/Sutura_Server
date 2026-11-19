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
