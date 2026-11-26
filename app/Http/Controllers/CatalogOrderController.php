<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CatalogOrder;
use App\Models\JobOrder;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Notifications\CatalogOrderPaymentStatusNotification;
use App\Notifications\NewCatalogOrderNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CatalogOrderController extends Controller
{
    /** Verify the authenticated user owns this store. */
    private function authorizeStore(int $storeId): Store
    {
        $store = Store::findOrFail($storeId);
        $user = Auth::user();

        // Must be the store owner or a staff/branch_manager belonging to this store
        $isOwner = $user->id === $store->owner_id;
        $isStaff = $user->staffProfile && $user->staffProfile->store_id === $store->id;

        if (! $isOwner && ! $isStaff) {
            abort(403, "You don't have access to this shop.");
        }

        return $store;
    }

    public function index($storeId, Request $request)
    {
        $this->authorizeStore($storeId);

        $query = CatalogOrder::with(['catalogItem.images', 'customer', 'branch:id,name'])
            ->where('store_id', $storeId);

        // Same "pinned staff only see their own branch" scoping already
        // used for jobs/appointments/customers — walk-in orders were the
        // one order type left without any branch attribution at all. Also
        // matches null-branch rows (every order that existed before this
        // column was added) so migrating in doesn't suddenly hide a branch
        // staff member's own pre-existing order history.
        $user = $request->user();
        if (! $user->hasRole('store_owner') && $user->staffProfile?->store_branch_id) {
            $branchId = $user->staffProfile->store_branch_id;
            $query->where(fn ($q) => $q->where('store_branch_id', $branchId)->orWhereNull('store_branch_id'));
        } elseif ($request->filled('branch_id')) {
            $branchId = (int) $request->branch_id;
            $mainBranchId = (int) StoreBranch::where('store_id', $storeId)->where('is_main', true)->value('id');
            if ($branchId === $mainBranchId) {
                $query->where(fn ($q) => $q->where('store_branch_id', $branchId)->orWhereNull('store_branch_id'));
            } else {
                $query->where('store_branch_id', $branchId);
            }
        }

        $orders = $query->latest()->get();

        return response()->json(['data' => $orders]);
    }

    /**
     * Walk-in only — a quick, immediate-sale record for a customer who came
     * into the store and ordered off the Design Catalog (as a made-to-order
     * reference). Store pickup only, matching the rest of the system's
     * exclusion of logistics/courier/delivery management.
     */
    public function store(Request $request, $storeId)
    {
        $store = $this->authorizeStore($storeId);

        $validated = $request->validate([
            'catalog_item_id' => [
                'required',
                Rule::exists('catalog_items', 'id')->where('store_id', $storeId),
            ],
            'store_branch_id' => ['nullable', Rule::exists('store_branches', 'id')->where('store_id', $storeId)],
            'selected_size' => 'nullable|string|max:50',
            'customer_id' => 'nullable|exists:users,id',
            'total_amount' => 'required|numeric|min:0',
            'payment_status' => 'required|in:pending,paid',
            // Model/migration have carried these three columns since day
            // one, and the Payments page's "Receipts to Verify" queue is
            // already built to expect them (usePayments.ts checks
            // payment_method !== 'cash' && payment_status === 'pending' to
            // decide whether a catalog order needs verification) — but this
            // endpoint never accepted them, so a real GCash/bank walk-in
            // sale had no way to actually record its reference/receipt,
            // and could never appear in that verification queue at all.
            'payment_method' => 'nullable|string|in:cash,gcash,paymaya',
            'payment_reference' => 'nullable|string|max:255',
            'payment_receipt_path' => 'nullable|string|max:2048',
        ]);

        // Same "auto-assign when there's only one branch" convenience
        // AppointmentController::store already gives owners/managers —
        // don't make a single-branch store pick from a dropdown of one.
        if (empty($validated['store_branch_id'])) {
            $userBranchId = $request->user()->staffProfile?->store_branch_id;
            if ($userBranchId) {
                $validated['store_branch_id'] = $userBranchId;
            } elseif ($store->branches()->count() === 1) {
                $validated['store_branch_id'] = $store->branches()->first()->id;
            }
        }

        // The Branch field on the walk-in order form is explicitly optional
        // ("Not specified" is a real, selectable option) — an owner at a
        // multi-branch store who leaves it blank used to save the order with
        // store_branch_id = NULL, permanently invisible under any branch
        // filter (`WHERE store_branch_id = ?` never matches NULL in SQL).
        // Same bug, same fix as JobOrderController@store: default to the
        // store's main branch rather than leaving it unset.
        if (empty($validated['store_branch_id'])) {
            $mainBranch = $store->branches()->where('is_main', true)->first();
            if ($mainBranch) {
                $validated['store_branch_id'] = $mainBranch->id;
            }
        }

        $validated['store_id'] = $storeId;
        $validated['type'] = 'walkin';
        $validated['status'] = 'ready';
        $validated['fulfillment_type'] = 'pickup';

        // Same reused-screenshot protection as JobOrderController@pay —
        // now that this endpoint actually records a reference (see above),
        // it needs the same check, and has to look across all three real
        // payment surfaces at this store (job order payments, other walk-in
        // orders, appointment deposits), not just its own table, since a
        // customer could just as easily reuse one screenshot across any of
        // them at the same counter.
        $duplicateReferenceWarning = null;
        if (! empty($validated['payment_reference'])) {
            $ref = $validated['payment_reference'];
            $dupJobOrder = JobOrder::where('store_id', $storeId)
                ->whereHas('payments', fn ($q) => $q->where('reference', $ref)->whereNull('rejected_at'))
                ->first();
            $dupCatalogOrder = $dupJobOrder ? null : CatalogOrder::where('store_id', $storeId)
                ->where('payment_reference', $ref)
                ->first();
            $dupAppointment = ($dupJobOrder || $dupCatalogOrder) ? null : Appointment::where('store_id', $storeId)
                ->where('payment_reference', $ref)
                ->first();

            if ($dupJobOrder) {
                $duplicateReferenceWarning = "This reference number was already used on job order {$dupJobOrder->order_number} — double-check this isn't a reused receipt before accepting.";
            } elseif ($dupCatalogOrder) {
                $duplicateReferenceWarning = "This reference number was already used on order {$dupCatalogOrder->order_number} — double-check this isn't a reused receipt before accepting.";
            } elseif ($dupAppointment) {
                $duplicateReferenceWarning = "This reference number was already used on an appointment deposit — double-check this isn't a reused receipt before accepting.";
            }
        }

        $order = CatalogOrder::create($validated);

        // Notify store owner of the new order (mirrors Job Orders/Appointments,
        // which already notify regardless of who — owner or staff — logged it).
        $store = Store::find($storeId);
        $storeOwner = $store?->owner;
        if ($storeOwner) {
            $storeOwner->notify(new NewCatalogOrderNotification($order));
        }

        return response()->json([
            'data' => $order->load(['catalogItem', 'customer', 'branch:id,name']),
            'warning' => $duplicateReferenceWarning,
        ], 201);
    }

    public function update(Request $request, $storeId, $orderId)
    {
        $this->authorizeStore($storeId);

        $order = CatalogOrder::where('store_id', $storeId)->findOrFail($orderId);

        $validated = $request->validate([
            'status' => 'required|in:pending,ready,completed,cancelled',
            'payment_status' => 'sometimes|in:pending,paid',
        ]);

        // A cancelled order voids a mistaken/duplicate entry — once the item has actually
        // moved (handed over / picked up) it must be handled through the normal lifecycle
        // instead, not silently erased. Walk-in orders (store()) are always created
        // directly as 'ready', never 'pending' — 'pending' only exists as a possible
        // future status for a non-instant order type — so 'ready' has to be a
        // cancellable state too, or every walk-in order becomes uncancellable forever.
        if ($validated['status'] === 'cancelled' && ! in_array($order->status, ['pending', 'ready'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only a pending or ready order can be cancelled.',
            ], 422);
        }

        $order->update($validated);

        return response()->json(['data' => $order->load(['catalogItem', 'customer', 'branch:id,name'])]);
    }

    /**
     * A one-time, in-the-moment discount the owner grants on an existing
     * walk-in order (e.g. a repeat customer) — not a standing coupon/promo
     * code. Catalog orders have no separate balance column, so the discount
     * reduces total_amount directly. Logged to the audit trail, same pattern
     * as JobOrderController::applyDiscount / AppointmentController's audit entries.
     */
    public function applyDiscount(Request $request, $storeId, $orderId)
    {
        $store = $this->authorizeStore($storeId);

        $order = CatalogOrder::where('store_id', $storeId)->findOrFail($orderId);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ]);

        $discountAmount = (float) $validated['amount'];

        // Lock the row for the duration of the transaction, same as
        // JobOrderController@applyDiscount — without it, two discounts
        // applied to the same walk-in order at nearly the same time could
        // both read the same starting total_amount and both apply on top
        // of it, silently discounting twice instead of stacking correctly.
        try {
            DB::transaction(function () use ($order, $discountAmount, $validated, $request, $store) {
                $locked = CatalogOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

                $currentTotal = (float) $locked->total_amount;
                if ($discountAmount > $currentTotal) {
                    throw new \RuntimeException('Discount cannot exceed the order total (₱'.number_format($currentTotal, 2).').');
                }

                $newTotal = round($currentTotal - $discountAmount, 2);
                $newDiscountTotal = round((float) ($locked->discount_amount ?? 0) + $discountAmount, 2);

                $locked->update([
                    'total_amount' => $newTotal,
                    'discount_amount' => $newDiscountTotal,
                ]);

                $store->auditLogs()->create([
                    'user_id' => $request->user()->id,
                    'action' => 'discount_applied',
                    'model_type' => CatalogOrder::class,
                    'model_id' => $locked->id,
                    'payload' => [
                        'amount' => $discountAmount,
                        'reason' => $validated['reason'] ?? null,
                    ],
                    'ip_address' => $request->ip(),
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Discount applied successfully.',
            'data' => $order->fresh(['catalogItem', 'customer', 'branch:id,name']),
        ]);
    }

    public function verifyPayment(Request $request, $storeId, $orderId)
    {
        $this->authorizeStore($storeId);

        $order = CatalogOrder::where('store_id', $storeId)->findOrFail($orderId);

        $validated = $request->validate([
            'payment_status' => 'required|in:pending,paid,rejected',
        ]);

        $oldPaymentStatus = $order->payment_status;

        $order->update([
            'payment_status' => $validated['payment_status'],
        ]);

        // Previously silent either way — same gap as the appointment
        // payment path, just for walk-in/RTW Catalog Orders.
        if (in_array($validated['payment_status'], ['paid', 'rejected'], true) && $validated['payment_status'] !== $oldPaymentStatus) {
            $order->customer?->notify(new CatalogOrderPaymentStatusNotification($order, $validated['payment_status']));
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated.',
            'data' => $order->load(['catalogItem', 'customer', 'branch:id,name']),
        ]);
    }
}
