<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff member's Home: the shop-floor numbers the thesis names (Orders in Production,
 * Quality Checks Needed, Pending Fittings) and "My Production Queue". Branch-scoped to the
 * branch the account is pinned to; an unpinned account sees the whole shop. Every number is
 * its own ->count() (never derived from the capped queue list) and nothing here is cached.
 */
class StaffOverviewController extends Controller
{
    private const IN_PRODUCTION = ['production', 'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing', 'final_adjustments', 'adjustments', 'in_repair'];

    private const QUALITY_CHECK = ['quality_check', 'qc_ironing', 'qc_check'];

    private const QUEUE_LIMIT = 8;

    public function show(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();
        $branchId = $user->staffProfile->store_branch_id ?? null;
        $scope = fn ($q) => $q->when($branchId, fn ($qq) => $qq->where('store_branch_id', $branchId));

        $jobs = fn () => $scope($store->jobOrders());
        $fittings = fn () => $scope($store->appointments())
            ->where('appointment_type', 'fitting')
            ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
            ->where('scheduled_at', '>=', now()->startOfDay());

        // "My production queue" = orders assigned to this person — the same rule
        // the order page uses for staff (JobOrderController::show), so every row
        // here opens. Owners/branch managers see the branch's queue.
        $isManager = $user->hasRole('store_owner') || $user->hasRole('branch_manager');
        $assignedToMe = fn ($q) => $q->where(fn ($w) => $w->where('assigned_staff_id', $user->id)->orWhere('assigned_to', $user->id));
        $isMine = fn (JobOrder $j) => (int) $j->assigned_staff_id === (int) $user->id || (int) $j->assigned_to === (int) $user->id;

        // Staff also see their assigned orders that haven't started yet or are on hold.
        $queueStatuses = array_merge($isManager ? [] : ['pending', 'on_hold'], ['design'], self::IN_PRODUCTION, self::QUALITY_CHECK, ['ready_for_fitting']);
        $queue = $jobs()->when(! $isManager, $assignedToMe)
            ->whereIn('status', $queueStatuses)
            ->with(['customer:id,name', 'service:id,name', 'catalogItem:id,name'])
            ->orderBy('due_date')->limit(40)->get()
            ->map(fn (JobOrder $j) => [
                'id' => $j->id,
                'order_number' => $j->order_number,
                'customer' => $j->customer?->name,
                'what' => $j->catalogItem?->name ?? $j->service?->name ?? $j->garment_category,
                'status' => $j->status,
                'due_date' => $j->due_date,
                'is_rush' => (bool) $j->is_rush,
                'is_mine' => $isMine($j),
                // Overdue, or due within two days.
                'urgent' => $j->due_date && $j->due_date->lte(now()->addDays(2)->endOfDay()),
            ])
            // Mine first, then by due date (already ordered).
            ->sortBy(fn ($row) => $row['is_mine'] ? 0 : 1)->values()->take(self::QUEUE_LIMIT);

        return response()->json(['success' => true, 'data' => [
            'orders_in_production' => $jobs()->whereIn('status', self::IN_PRODUCTION)->count(),
            'quality_checks_needed' => $jobs()->whereIn('status', self::QUALITY_CHECK)->count(),
            'pending_fittings' => $fittings()->count(),
            // Same "assigned to me" rule as the queue and the order page.
            'my_jobs' => $assignedToMe($jobs()->whereNotIn('status', ['completed', 'cancelled', 'rejected']))->count(),
            'queue' => $queue,
            'fittings' => $fittings()->with(['customer:id,name', 'jobOrder:id,order_number'])
                ->orderBy('scheduled_at')->limit(5)->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'customer' => $a->customer?->name,
                    'order_number' => $a->jobOrder?->order_number,
                    'scheduled_at' => $a->scheduled_at,
                    'status' => $a->status,
                    'is_mine' => (int) $a->assigned_staff_id === (int) $user->id,
                ]),
        ]]);
    }
}
