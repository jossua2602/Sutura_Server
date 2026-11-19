<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The shop's own list of places customers can pay. Owners manage the whole shop's list; a branch
 * manager manages only their branch's methods. Customers read the active ones (public).
 */
class PaymentMethodController extends Controller
{
    private function managerBranch(Request $request): ?int
    {
        return $request->user()->hasRole('store_owner') ? null : (int) ($request->user()->staffProfile->store_branch_id ?? 0);
    }

    /** Owners may touch anything; a branch manager only methods scoped to their own branch. */
    private function denied(Request $request, PaymentMethod $m, Store $store): ?JsonResponse
    {
        if ($m->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }
        if (! $request->user()->hasRole('store_owner') && (int) $m->store_branch_id !== $this->managerBranch($request)) {
            return response()->json(['success' => false, 'message' => 'Only the owner can change shop-wide payment methods.'], 403);
        }

        return null;
    }

    private function rules(Store $store): array
    {
        return [
            // Only GCash accounts are accepted (cash is paid at the shop, no account needed).
            'kind' => ['required', Rule::in(['gcash'])],
            'name' => ['required', 'string', 'max:80'],
            'account_name' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:80'],
            'qr_path' => ['nullable', 'string', 'max:2048'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'store_branch_id' => ['nullable', 'integer', Rule::exists('store_branches', 'id')->where('store_id', $store->id)],
        ];
    }

    public function index(Request $request, Store $store): JsonResponse
    {
        $branch = $this->managerBranch($request);
        $methods = $store->paymentMethods()->with('branch:id,name')
            ->when($branch, fn ($q) => $q->where(fn ($qq) => $qq->whereNull('store_branch_id')->orWhere('store_branch_id', $branch)))
            ->orderBy('id')->get();

        return response()->json(['success' => true, 'data' => $methods]);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        // A branch manager's methods always belong to their own branch — whatever branch they send is ignored.
        $branch = $this->managerBranch($request);
        $rules = $this->rules($store);
        if ($branch !== null) {
            unset($rules['store_branch_id']);
        }
        $data = $request->validate($rules);
        if ($branch !== null) {
            $data['store_branch_id'] = $branch;
        }
        $method = $store->paymentMethods()->create($data);

        return response()->json(['success' => true, 'data' => $method], 201);
    }

    public function update(Request $request, Store $store, PaymentMethod $paymentMethod): JsonResponse
    {
        if ($denied = $this->denied($request, $paymentMethod, $store)) {
            return $denied;
        }
        $rules = $this->rules($store);
        if ($this->managerBranch($request) !== null) {
            unset($rules['store_branch_id']);
        }
        $data = $request->validate($rules);
        $paymentMethod->update($data);

        return response()->json(['success' => true, 'data' => $paymentMethod->fresh()]);
    }

    public function destroy(Request $request, Store $store, PaymentMethod $paymentMethod): JsonResponse
    {
        if ($paymentMethod->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        // ?branch_id=X on an "Entire shop" method: remove it from that branch only.
        // The other branches each keep it (as their own branch-only method).
        $fromBranch = $request->integer('branch_id') ?: null;
        if ($paymentMethod->store_branch_id === null && $fromBranch !== null) {
            if (! $store->branches()->whereKey($fromBranch)->exists()) {
                return response()->json(['success' => false, 'message' => 'Branch not found.'], 404);
            }
            // A branch manager may take a shop-wide method off their own branch, never another's.
            $manager = $this->managerBranch($request);
            if ($manager !== null && $manager !== $fromBranch) {
                return response()->json(['success' => false, 'message' => 'You can only change your own branch.'], 403);
            }
            $this->removeFromBranch($store, $paymentMethod, $fromBranch);

            return response()->json(['success' => true, 'scope' => 'branch']);
        }

        if ($denied = $this->denied($request, $paymentMethod, $store)) {
            return $denied;
        }
        // Past payments keep their record (the link is nulled), so history survives.
        $paymentMethod->delete();

        return response()->json(['success' => true, 'scope' => $paymentMethod->store_branch_id ? 'branch' : 'shop']);
    }

    /**
     * Turns a shop-wide method into one branch-only method per remaining branch,
     * leaving $branchId without it. The original row moves to one remaining
     * branch (the main one when it stays), so payments already linked to it
     * keep their link; the other branches get copies.
     */
    private function removeFromBranch(Store $store, PaymentMethod $method, int $branchId): void
    {
        DB::transaction(function () use ($store, $method, $branchId) {
            $remaining = $store->branches()->whereKeyNot($branchId)->orderByDesc('is_main')->orderBy('id')->pluck('id');
            if ($remaining->isEmpty()) {
                $method->delete();

                return;
            }
            $copy = $method->only(['kind', 'name', 'account_name', 'account_number', 'qr_path', 'instructions', 'is_active']);
            foreach ($remaining->slice(1) as $otherBranch) {
                $store->paymentMethods()->create($copy + ['store_branch_id' => $otherBranch]);
            }
            $method->update(['store_branch_id' => $remaining->first()]);
        });
    }

    /** Customer-facing: active methods for a shop, plus the branch's own when ?branch_id is given. */
    public function publicIndex(Request $request, Store $store): JsonResponse
    {
        $branch = $request->integer('branch_id') ?: null;
        // ?any_branch=1: every active method in the shop, whichever branch it belongs to
        // (the "Online Order" button checks whether the shop takes GCash at all).
        $anyBranch = $request->boolean('any_branch');
        $methods = $store->paymentMethods()->where('is_active', true)
            ->when(! $anyBranch, fn ($q) => $q->where(fn ($w) => $w->whereNull('store_branch_id')->when($branch, fn ($qq) => $qq->orWhere('store_branch_id', $branch))))
            ->orderBy('id')
            ->get(['id', 'store_branch_id', 'kind', 'name', 'account_name', 'account_number', 'qr_path', 'instructions']);

        return response()->json(['success' => true, 'data' => $methods]);
    }
}
