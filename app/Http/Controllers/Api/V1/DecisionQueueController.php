<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Store;
use App\Support\BranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Needs your decision" on the owner's Home: appointment requests waiting for approve/reject and
 * e-payment proofs waiting for verification. Deliberately NOT cached and NOT derived from the
 * analytics payload — the counts are independent ->count() queries so they stay true however many
 * rows exist, and a new booking shows up on the next refresh.
 */
class DecisionQueueController extends Controller
{
    private const PREVIEW = 5;

    public function show(Request $request, Store $store): JsonResponse
    {
        // A branch manager only their own branch; the owner the branch picked
        // in the switcher, or the whole shop for "All Branches".
        $branchId = BranchScope::resolve($request, $store);

        $apptQuery = $store->appointments()->where('status', 'pending')
            ->when($branchId, fn ($q) => $q->where('store_branch_id', $branchId));
        $apptCount = (clone $apptQuery)->count();
        $appointments = (clone $apptQuery)
            ->with(['customer:id,name', 'service:id,name', 'catalogItem:id,name', 'servicePackage:id,name', 'branch:id,name'])
            ->orderBy('scheduled_at')->limit(self::PREVIEW)->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'customer' => $a->customer?->name,
                'what' => $a->catalogItem?->name ?? $a->servicePackage?->name ?? $a->service?->name,
                'appointment_type' => $a->appointment_type,
                'purpose_label' => $a->purpose_label,
                'scheduled_at' => $a->scheduled_at,
                'branch' => $a->branch?->name,
                'intake_channel' => $a->intake_channel,
                'needs_new_time' => $a->outcome === 'rescheduled',
            ]);

        // E-payment proofs on job orders (cash verifies instantly, so it never waits here).
        $payQuery = Payment::whereHas('jobOrder', fn ($q) => $q->where('store_id', $store->id)
            ->when($branchId, fn ($qq) => $qq->where('store_branch_id', $branchId)))
            ->whereNull('verified_at')->whereNull('rejected_at');
        $payCount = (clone $payQuery)->count();
        $payments = (clone $payQuery)->with('jobOrder:id,order_number,customer_id', 'jobOrder.customer:id,name')
            ->latest()->limit(self::PREVIEW)->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'job_order_id' => $p->job_order_id,
                'order_number' => $p->jobOrder?->order_number,
                'customer' => $p->jobOrder?->customer?->name,
                'amount' => (float) $p->amount,
                'method' => $p->payment_method,
                'created_at' => $p->created_at,
            ]);

        // Deposit proofs customers attached when booking (GCash/PayMaya) that nobody has verified yet.
        $depQuery = $store->appointments()->whereIn('status', ['pending', 'confirmed'])
            ->where('payment_status', 'pending')->whereNotNull('payment_receipt_path')
            ->whereIn('payment_method', ['gcash', 'paymaya'])
            ->when($branchId, fn ($q) => $q->where('store_branch_id', $branchId));
        $depCount = (clone $depQuery)->count();
        $deposits = (clone $depQuery)->with('customer:id,name')->latest()->limit(self::PREVIEW)->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'customer' => $a->customer?->name,
                'method' => $a->payment_method,
                'scheduled_at' => $a->scheduled_at,
            ]);

        return response()->json(['success' => true, 'data' => [
            'appointments' => ['count' => $apptCount, 'items' => $appointments],
            'payments' => ['count' => $payCount, 'items' => $payments],
            'deposits' => ['count' => $depCount, 'items' => $deposits],
        ]]);
    }
}
