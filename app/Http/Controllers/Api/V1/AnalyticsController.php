<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogOrder;
use App\Models\Payment;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\StoreSubscription;
use App\Models\SubscriptionEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Per-branch performance breakdown — an owner-level strategic view (which
     * location is actually profitable), so it is deliberately not exposed to
     * branch managers the way the regular branch-scoped analytics are.
     */
    /**
     * start_date/end_date used to be read straight off the query string and
     * handed to Carbon::parse()/whereBetween() unvalidated — a malformed
     * value (e.g. a garbled URL edit, not just malicious input) crashed the
     * endpoint with an uncaught Carbon\Exceptions\InvalidFormatException,
     * leaking a full stack trace with server file paths in the response.
     */
    private function validateDateRange(Request $request): array
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        return [$validated['start_date'] ?? null, $validated['end_date'] ?? null];
    }

    public function branchComparison(Request $request, Store $store): JsonResponse
    {
        // Comparing branches only means something with 2+ branches, which is Premium-only.
        if ($denied = \App\Support\PlanGate::require($store, 'premium', 'Branch comparison reports')) {
            return $denied;
        }

        @ini_set('max_execution_time', 120);

        [$startDate, $endDate] = $this->validateDateRange($request);

        $cacheKey = "branch_comparison_{$store->id}_".($startDate ?? 'all').'_'.($endDate ?? 'all');
        if (! app()->environment('testing')) {
            $cached = Cache::driver('file')->get($cacheKey);
            if ($cached) {
                return response()->json($cached);
            }
        }

        $jobsQuery = $store->jobOrders();
        if ($startDate && $endDate) {
            $jobsQuery->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
        }
        $branchJobStats = $jobsQuery
            ->selectRaw("
                store_branch_id,
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                COALESCE(SUM(total_amount), 0) as sum_total_amount,
                COALESCE(SUM(balance), 0) as sum_balance,
                COALESCE(SUM(discount_amount), 0) as sum_discount_amount
            ")
            ->groupBy('store_branch_id')
            ->get()
            ->keyBy(fn ($item) => $item->store_branch_id ? (string) $item->store_branch_id : 'null');

        $appointmentsQuery = $store->appointments();
        if ($startDate && $endDate) {
            $appointmentsQuery->whereBetween('scheduled_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
        }
        $branchAppointmentStats = $appointmentsQuery
            ->selectRaw('store_branch_id, COUNT(*) as total_appointments')
            ->groupBy('store_branch_id')
            ->get()
            ->keyBy(fn ($item) => $item->store_branch_id ? (string) $item->store_branch_id : 'null');

        $catalogOrdersQuery = CatalogOrder::where('store_id', $store->id);
        if ($startDate && $endDate) {
            $catalogOrdersQuery->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
        }
        $branchCatalogStats = $catalogOrdersQuery
            ->selectRaw('store_branch_id, COUNT(*) as total_walkin_orders')
            ->groupBy('store_branch_id')
            ->get()
            ->keyBy(fn ($item) => $item->store_branch_id ? (string) $item->store_branch_id : 'null');

        $staffCounts = StaffProfile::where('store_id', $store->id)
            ->whereNotNull('store_branch_id')
            ->selectRaw('store_branch_id, COUNT(*) as total_staff')
            ->groupBy('store_branch_id')
            ->pluck('total_staff', 'store_branch_id');

        $rejectedPayments = Payment::whereNotNull('payments.rejected_at')
            ->join('job_orders', 'payments.job_order_id', '=', 'job_orders.id')
            ->where('job_orders.store_id', $store->id)
            ->when($startDate && $endDate, fn ($q) => $q->whereBetween('job_orders.created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']))
            ->selectRaw('job_orders.store_branch_id, COALESCE(SUM(payments.amount), 0) as rejected_amount')
            ->groupBy('job_orders.store_branch_id')
            ->get()
            ->keyBy(fn ($item) => $item->store_branch_id ? (string) $item->store_branch_id : 'null');

        $forfeitedPayments = Payment::whereNull('payments.rejected_at')
            ->join('job_orders', 'payments.job_order_id', '=', 'job_orders.id')
            ->where('job_orders.store_id', $store->id)
            ->where('job_orders.status', 'cancelled')
            ->where('job_orders.cancellation_reason', 'forfeited_deposit_abandoned')
            ->when($startDate && $endDate, fn ($q) => $q->whereBetween('job_orders.created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']))
            ->selectRaw('job_orders.store_branch_id, COALESCE(SUM(payments.amount), 0) as forfeited_amount')
            ->groupBy('job_orders.store_branch_id')
            ->get()
            ->keyBy(fn ($item) => $item->store_branch_id ? (string) $item->store_branch_id : 'null');

        $branches = $store->branches()->orderByDesc('is_main')->get();

        $buildRow = function (?int $branchId, string $name, bool $isMain) use (
            $branchJobStats, $branchAppointmentStats, $branchCatalogStats,
            $staffCounts, $rejectedPayments, $forfeitedPayments
        ) {
            $key = $branchId ? (string) $branchId : 'null';
            $jobStat = $branchJobStats->get($key);
            $totalJobs = (int) ($jobStat->total_jobs ?? 0);
            $completedJobs = (int) ($jobStat->completed_jobs ?? 0);
            $sumTotal = (float) ($jobStat->sum_total_amount ?? 0);
            $sumBal = (float) ($jobStat->sum_balance ?? 0);
            $sumDisc = (float) ($jobStat->sum_discount_amount ?? 0);

            $apptStat = $branchAppointmentStats->get($key);
            $catStat = $branchCatalogStats->get($key);
            $rejStat = $rejectedPayments->get($key);
            $forfStat = $forfeitedPayments->get($key);

            return [
                'branch_id' => $branchId,
                'branch_name' => $name,
                'is_main' => $isMain,
                'total_jobs' => $totalJobs,
                'completed_jobs' => $completedJobs,
                'completion_rate' => $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0,
                'total_revenue' => max(0.0, $sumTotal - $sumBal - $sumDisc),
                'total_outstanding_balance' => $sumBal,
                'total_appointments' => (int) ($apptStat->total_appointments ?? 0),
                'total_walkin_orders' => (int) ($catStat->total_walkin_orders ?? 0),
                'total_staff' => $branchId ? (int) ($staffCounts[$branchId] ?? 0) : 0,
                'rejected_payments_amount' => (float) ($rejStat->rejected_amount ?? 0),
                'forfeited_deposit_amount' => (float) ($forfStat->forfeited_amount ?? 0),
            ];
        };

        $rows = $branches->map(fn ($branch) => $buildRow($branch->id, $branch->name, (bool) $branch->is_main))->values();

        // Jobs/appointments never tagged to a branch (legacy data, or a
        // single-branch store) still need to be visible somewhere, not silently
        // dropped from the comparison.
        $unassigned = $buildRow(null, 'Unassigned', false);
        if ($unassigned['total_jobs'] > 0 || $unassigned['total_appointments'] > 0 || $unassigned['total_walkin_orders'] > 0) {
            $rows->push($unassigned);
        }

        $responsePayload = [
            'success' => true,
            'data' => $rows->values()->toArray(),
        ];

        if (! app()->environment('testing')) {
            Cache::driver('file')->put($cacheKey, $responsePayload, 60);
        }

        return response()->json($responsePayload);
    }

    /**
     * Per-staff productivity breakdown — an owner-level strategic view (which
     * staff member is completing/carrying the most work), so like
     * branchComparison() it is deliberately not exposed to branch managers.
     */
    public function staffProductivity(Request $request, Store $store): JsonResponse
    {
        // Sales & staff reports are a Pro feature; branch comparison stays Premium.
        if ($denied = \App\Support\PlanGate::require($store, 'pro', 'Sales and staff reports')) {
            return $denied;
        }

        @ini_set('max_execution_time', 120);

        [$startDate, $endDate] = $this->validateDateRange($request);
        $branchId = $request->filled('branch_id') ? $request->branch_id : null;

        $cacheKey = "staff_productivity_{$store->id}_b{$branchId}_s{$startDate}_e{$endDate}";
        if (! app()->environment('testing')) {
            $cached = Cache::driver('file')->get($cacheKey);
            if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
                return response()->json($cached);
            }
        }

        $scopeToRange = function ($query) use ($startDate, $endDate) {
            if ($startDate && $endDate) {
                $query->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
            }

            return $query;
        };

        $staffQuery = $store->staff()->with('user:id,name')->where('is_active', true);
        if ($branchId) {
            $staffQuery->where('store_branch_id', $branchId);
        }

        $rows = $staffQuery->get()->map(function (StaffProfile $profile) use ($store, $scopeToRange) {
            // A job's single assigned_staff_id only reflects whichever
            // production stage was assigned first — a staff member working a
            // later stage (e.g. sewing, when someone else did cutting first)
            // would otherwise never show up here despite doing real work on
            // the job, so also credit jobs where they're assigned to ANY
            // stage via the Multi-Stage Staff Assignment pivot.
            $jobsQuery = $store->jobOrders()->where(function ($q) use ($profile) {
                $q->where('assigned_staff_id', $profile->user_id)
                    ->orWhereHas('staffStages', function ($sq) use ($profile) {
                        // staffStages is a belongsToMany(User::class, ...), so the
                        // related model's own key is `id`, not `user_id` — the
                        // pivot's user_id is what the join already matches on.
                        // Qualified with the table name since both job_orders
                        // and users have an `id` column, which is otherwise
                        // ambiguous inside this EXISTS subquery.
                        $sq->where('users.id', $profile->user_id);
                    });
            });
            $scopeToRange($jobsQuery);

            $totalJobs = $jobsQuery->count();
            $completedJobs = (clone $jobsQuery)->where('status', 'completed')->count();

            // Average Final Adjustment rounds across this staff member's jobs
            // — a real quality signal (which tailor's work needs redoing most
            // often), possible now that adjustment_count is actually tracked
            // (see JobOrderController@update) but was never connected to a
            // per-staff view before.
            $avgAdjustments = $totalJobs > 0
                ? round((float) (clone $jobsQuery)->avg('adjustment_count'), 1)
                : 0;

            return [
                'staff_id' => $profile->user_id,
                'name' => $profile->user?->name,
                'role' => $profile->role,
                'total_jobs' => $totalJobs,
                'completed_jobs' => $completedJobs,
                'completion_rate' => $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0,
                // Deliberately no per-staff revenue figure — money attributed
                // to an individual reads as a commission/piece-rate basis,
                // which drifts into payroll (excluded by the thesis scope).
                // Work output only: job counts, completion rate, rework.
                'avg_adjustments' => $avgAdjustments,
            ];
        })->sortByDesc('completed_jobs')->values();

        $responsePayload = [
            'success' => true,
            'data' => $rows->values()->toArray(),
        ];

        if (! app()->environment('testing')) {
            Cache::driver('file')->put($cacheKey, $responsePayload, 60);
        }

        return response()->json($responsePayload);
    }

    public function index(Request $request, Store $store): JsonResponse
    {
        @ini_set('max_execution_time', 120);
        [$startDate, $endDate] = $this->validateDateRange($request);

        $isBranchManager = $request->user()?->hasRole('branch_manager') ? 1 : 0;
        $branchId = null;
        if ($isBranchManager) {
            $branchId = $request->user()->staffProfile->store_branch_id ?? null;
        } elseif ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
        }

        $cacheKey = "store_analytics_{$store->id}_b{$branchId}_s{$startDate}_e{$endDate}_bm{$isBranchManager}";

        if (! app()->environment('testing')) {
            $cached = Cache::driver('file')->get($cacheKey);
            if ($cached) {
                return response()->json($cached);
            }
        }

        // Branch-scoped base queries for the triage KPIs. Fresh builder each call and
        // NOT date-filtered — these are "current state" metrics (overdue/pending/etc.),
        // but they must still respect the selected branch.
        $branchJobs = fn () => $branchId
            ? $store->jobOrders()->where('store_branch_id', $branchId)
            : $store->jobOrders();
        $branchAppointments = fn () => $branchId
            ? $store->appointments()->where('store_branch_id', $branchId)
            : $store->appointments();

        // Overview Stats
        $jobsQuery = $store->jobOrders();
        $appointmentsQuery = $store->appointments();

        if ($branchId) {
            $jobsQuery->where('store_branch_id', $branchId);
            $appointmentsQuery->where('store_branch_id', $branchId);
        }

        if ($startDate && $endDate) {
            // Need to append time to ensure end date is inclusive of that whole day
            $jobsQuery->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
            $appointmentsQuery->whereBetween('scheduled_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
        }

        // Consolidated overview aggregates for jobs (1 query instead of 6)
        $jobsOverview = (clone $jobsQuery)
            ->selectRaw("
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                COALESCE(SUM(total_amount), 0) as total_amount,
                COALESCE(SUM(balance), 0) as total_balance,
                COALESCE(SUM(discount_amount), 0) as total_discount
            ")
            ->first();

        $totalJobs = (int) ($jobsOverview->total_jobs ?? 0);
        $completedJobs = (int) ($jobsOverview->completed_jobs ?? 0);
        $totalRevenue = (float) ($jobsOverview->total_amount ?? 0) - (float) ($jobsOverview->total_balance ?? 0) - (float) ($jobsOverview->total_discount ?? 0);
        $totalBalance = (float) ($jobsOverview->total_balance ?? 0);

        // Consolidated appointment aggregates (1 query instead of 3)
        $appointmentsOverview = (clone $appointmentsQuery)
            ->selectRaw("
                SUM(CASE WHEN status NOT IN ('cancelled', 'no_show') THEN 1 ELSE 0 END) as convertible_count,
                SUM(CASE WHEN status NOT IN ('cancelled', 'no_show') AND job_order_id IS NOT NULL THEN 1 ELSE 0 END) as converted_count,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as upcoming_count
            ")
            ->first();

        $convertibleAppointments = (int) ($appointmentsOverview->convertible_count ?? 0);
        $convertedAppointments = (int) ($appointmentsOverview->converted_count ?? 0);
        $bookingConversionRate = $convertibleAppointments > 0
            ? round(($convertedAppointments / $convertibleAppointments) * 100, 1)
            : 0;

        $upcomingAppointments = (int) ($appointmentsOverview->upcoming_count ?? 0);

        // Store-wide counts below follow the selected branch too; only the
        // consolidated view (no branch) counts the whole shop.
        $totalStaff = $branchId ? $store->staff()->where('store_branch_id', $branchId)->count() : $store->staff()->count();
        // The store_customers pivot is only populated by the CRM's own "Add
        // Customer" form — a customer who came in via a job order, walk-in
        // creation, or public appointment booking never gets attached to it,
        // so counting the pivot alone showed 0 for stores whose customers all
        // arrived through those other paths. Database UNION performs distinct
        // count inside the engine in 1 query without transferring ID arrays over network.
        // Same rule as the Customers list (CustomerController::index): a branch
        // counts customers with an order or appointment there; "Add Customer"
        // entries (no branch) count on the Main Shop and in the consolidated view.
        $mainBranchId = (int) $store->branches()->where('is_main', true)->value('id');
        $totalCustomers = DB::table(function ($q) use ($store, $branchId, $mainBranchId) {
            $jobs = DB::table('job_orders')
                ->select('customer_id')
                ->where('store_id', $store->id)
                ->whereNotNull('customer_id')
                ->when($branchId, fn ($qq) => $qq->where('store_branch_id', $branchId));
            $appointments = DB::table('appointments')
                ->select('customer_id')
                ->where('store_id', $store->id)
                ->whereNotNull('customer_id')
                ->when($branchId, fn ($qq) => $qq->where('store_branch_id', $branchId));

            if (! $branchId || (int) $branchId === $mainBranchId) {
                $q->select('user_id as customer_id')
                    ->from('store_customers')
                    ->where('store_id', $store->id)
                    ->union($jobs)
                    ->union($appointments);
            } else {
                $q->fromSub($jobs->union($appointments), 'branch_cust')->select('customer_id');
            }
        }, 'all_cust')->count();

        // Jobs by status breakdown — used for pie chart in Reports page
        $jobsByStatus = (clone $jobsQuery)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => ['status' => $row->status, 'count' => (int) $row->count])
            ->values()
            ->toArray();

        // Orders by garment category — descriptive breakdown of what the store
        // is actually being asked to make (barong vs. gown vs. alterations,
        // etc.), the literal core of a "tailoring" report. Reports previously
        // had no view of this at all, only revenue/status/branch/staff
        // breakdowns — nothing tied to the garment itself. Purely descriptive
        // counts/sums over the same date-range/branch scope as every other
        // KPI here, not predictive — stays within the thesis's descriptive-
        // reporting scope (no forecasting).
        $garmentBreakdown = (clone $jobsQuery)
            ->whereNotNull('garment_category')
            ->select(
                'garment_category',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total_amount) as revenue')
            )
            ->groupBy('garment_category')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'garment_category' => $row->garment_category,
                'count' => (int) $row->count,
                'revenue' => (float) $row->revenue,
            ])
            ->values()
            ->toArray();

        // Compute revenue data split into 4 buckets across the selected range
        // (defaults to the current month), branch-scoped.
        $rangeStart = $startDate ? Carbon::parse($startDate)->startOfDay() : now()->startOfMonth();
        $rangeEnd = $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfMonth();
        $rangeSeconds = max(1, abs($rangeStart->diffInSeconds($rangeEnd)));

        $jobsThisMonth = $branchJobs()
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->get();

        // Bucket labels used to be hard-coded "Week 1"-"Week 4" regardless of
        // the selected range — correct-looking for the "This Month" default,
        // but nonsensical once a period selector actually varies the range
        // (see the dashboard's Business Performance chart): a full year
        // split into 4 buckets isn't "Week 1", it's roughly a quarter. Label
        // each bucket by its own real start date instead, which reads
        // correctly no matter how wide the range is.
        $bucketSeconds = $rangeSeconds / 4;
        $revenueData = [];
        for ($i = 0; $i < 4; $i++) {
            $bucketStart = $rangeStart->copy()->addSeconds((int) round($i * $bucketSeconds));
            // 'date' (ISO) alongside the display 'month' label — the frontend's
            // "vs previous period" trend badge needs to know which buckets are
            // still in the future (e.g. viewing "This Month" on the 8th, the
            // Aug 16/24 buckets haven't happened yet). Comparing against a
            // not-yet-elapsed bucket always reads as revenue "dropping to
            // zero," which is just time passing, not a real decline.
            $revenueData[] = ['month' => $bucketStart->format('M j'), 'date' => $bucketStart->toDateString(), 'revenue' => 0];
        }

        foreach ($jobsThisMonth as $job) {
            $elapsed = abs($rangeStart->diffInSeconds($job->created_at));
            $bucket = (int) floor(($elapsed / $rangeSeconds) * 4);
            $bucket = max(0, min(3, $bucket));
            // Same discount-vs-collected-cash fix as total_revenue/branchComparison
            // above — applyDiscount reduces balance directly, not total_amount,
            // so this trend chart was counting discounts as revenue too.
            $revenue = floatval($job->total_amount) - floatval($job->balance) - floatval($job->discount_amount ?? 0);
            if ($revenue > 0) {
                $revenueData[$bucket]['revenue'] += $revenue;
            }
        }

        $recentJobs = $branchJobs()
            ->with('customer')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->values()
            ->all();

        // ── Consolidated KPI Metrics (1 single query instead of 10+) ─────────
        $today = now()->toDateString();
        $nextWeek = now()->addDays(7)->toDateString();

        $kpiAggregates = $branchJobs()
            ->selectRaw("
                SUM(CASE WHEN status NOT IN ('completed', 'cancelled', 'on_hold', 'rejected') AND due_date IS NOT NULL AND due_date < ? THEN 1 ELSE 0 END) as overdue_jobs,
                SUM(CASE WHEN payment_status = 'unpaid' AND status != 'cancelled' THEN 1 ELSE 0 END) as pending_deposit_jobs,
                SUM(CASE WHEN status = 'ready_for_pickup' THEN 1 ELSE 0 END) as ready_for_pickup_jobs,
                SUM(CASE WHEN is_rush = true AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as rush_jobs_active,
                SUM(CASE WHEN status = 'completed' AND payment_status != 'paid' AND balance > 0 THEN 1 ELSE 0 END) as completed_unpaid_count,
                SUM(CASE WHEN status NOT IN ('completed', 'cancelled', 'rejected', 'on_hold') AND payment_status = 'unpaid' AND total_amount > 0 THEN 1 ELSE 0 END) as pending_dp_count,
                SUM(CASE WHEN status IN ('pending', 'design', 'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing', 'ready_for_fitting', 'final_adjustments', 'qc_ironing', 'ready_for_pickup') AND due_date = ? THEN 1 ELSE 0 END) as due_today_count,
                SUM(CASE WHEN status IN ('pending', 'design', 'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing', 'ready_for_fitting', 'final_adjustments', 'qc_ironing', 'ready_for_pickup') AND due_date > ? AND due_date <= ? THEN 1 ELSE 0 END) as due_this_week_count,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as completed_total_amount,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN discount_amount ELSE 0 END), 0) as completed_discount_amount
            ", [$today, $today, $today, $nextWeek])
            ->first();

        $overdueJobs = (int) ($kpiAggregates->overdue_jobs ?? 0);
        $pendingDepositJobs = (int) ($kpiAggregates->pending_deposit_jobs ?? 0);
        $readyForPickupJobs = (int) ($kpiAggregates->ready_for_pickup_jobs ?? 0);
        $rushJobsActive = (int) ($kpiAggregates->rush_jobs_active ?? 0);
        $completedUnpaidJobsCount = (int) ($kpiAggregates->completed_unpaid_count ?? 0);
        $pendingDpJobsCount = (int) ($kpiAggregates->pending_dp_count ?? 0);
        $dueTodayCount = (int) ($kpiAggregates->due_today_count ?? 0);
        $dueThisWeekCount = (int) ($kpiAggregates->due_this_week_count ?? 0);

        $completedTotalAmount = (float) ($kpiAggregates->completed_total_amount ?? 0);
        $completedDiscountAmount = (float) ($kpiAggregates->completed_discount_amount ?? 0);

        // Today's revenue: sum of payments created today
        $todayRevenue = (float) Payment::whereHas('jobOrder', function ($q) use ($store, $branchId) {
            $q->where('store_id', $store->id);
            if ($branchId) {
                $q->where('store_branch_id', $branchId);
            }
        })->whereDate('created_at', $today)->sum('amount');

        // Completion rate
        $completionRate = $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0;

        // Average order value (from completed jobs)
        $avgOrderValue = $completedJobs > 0
            ? round(($completedTotalAmount - $completedDiscountAmount) / $completedJobs, 2)
            : 0;

        // Average actual turnaround (days from creation to completion), for
        // jobs completed within the selected range
        $completedTurnarounds = (clone $jobsQuery)->where('status', 'completed')->get(['created_at', 'updated_at']);
        $avgTurnaroundDays = $completedTurnarounds->isNotEmpty()
            ? round($completedTurnarounds->avg(fn ($job) => $job->created_at->diffInDays($job->updated_at)), 1)
            : null;

        // Outstanding balances ledger — who owes how much
        $outstandingBalances = $branchJobs()
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['cancelled'])
            ->with('customer:id,name,phone')
            ->orderByDesc('balance')
            ->take(20)
            ->get(['id', 'order_number', 'customer_id', 'total_amount', 'balance', 'discount_amount', 'due_date', 'status'])
            ->map(fn ($job) => [
                'id' => $job->id,
                'order_number' => $job->order_number,
                'customer' => $job->customer ? ['id' => $job->customer->id, 'name' => $job->customer->name, 'phone' => $job->customer->phone] : null,
                'total_amount' => (float) $job->total_amount,
                'balance' => (float) $job->balance,
                'discount_amount' => (float) ($job->discount_amount ?? 0),
                'due_date' => $job->due_date,
                'status' => $job->status,
            ])
            ->values()
            ->all();

        // Completed jobs still owing a balance
        $completedUnpaidJobs = $completedUnpaidJobsCount > 0
            ? $branchJobs()
                ->where('status', 'completed')
                ->where('payment_status', '!=', 'paid')
                ->where('balance', '>', 0)
                ->with('customer:id,name,phone')
                ->orderByDesc('balance')
                ->take(20)
                ->get(['id', 'order_number', 'customer_id', 'total_amount', 'balance', 'discount_amount', 'due_date', 'status', 'payment_status'])
                ->map(fn ($job) => [
                    'id' => $job->id,
                    'order_number' => $job->order_number,
                    'customer' => $job->customer ? ['id' => $job->customer->id, 'name' => $job->customer->name, 'phone' => $job->customer->phone] : null,
                    'total_amount' => (float) $job->total_amount,
                    'balance' => (float) $job->balance,
                    'discount_amount' => (float) ($job->discount_amount ?? 0),
                    'due_date' => $job->due_date,
                    'status' => $job->status,
                    'payment_status' => $job->payment_status,
                ])
                ->values()
                ->all()
            : [];

        // Active jobs with zero downpayment collected
        $pendingDpJobsList = $pendingDpJobsCount > 0
            ? $branchJobs()
                ->whereNotIn('status', ['completed', 'cancelled', 'rejected', 'on_hold'])
                ->where('payment_status', 'unpaid')
                ->where('total_amount', '>', 0)
                ->with('customer:id,name,phone')
                ->latest()
                ->take(20)
                ->get(['id', 'order_number', 'customer_id', 'total_amount', 'balance', 'discount_amount', 'due_date', 'status', 'payment_status'])
                ->map(fn ($job) => [
                    'id' => $job->id,
                    'order_number' => $job->order_number,
                    'customer' => $job->customer ? ['id' => $job->customer->id, 'name' => $job->customer->name, 'phone' => $job->customer->phone] : null,
                    'total_amount' => (float) $job->total_amount,
                    'balance' => (float) $job->balance,
                    'discount_amount' => (float) ($job->discount_amount ?? 0),
                    'due_date' => $job->due_date,
                    'status' => $job->status,
                    'payment_status' => $job->payment_status,
                ])
                ->values()
                ->all()
            : [];

        // Due Today / Due This Week
        $dueJobsActiveStatuses = ['pending', 'design', 'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing', 'ready_for_fitting', 'final_adjustments', 'qc_ironing', 'ready_for_pickup'];
        $dueJobFields = ['id', 'order_number', 'customer_id', 'total_amount', 'balance', 'discount_amount', 'due_date', 'status', 'payment_status'];
        $mapDueJob = fn ($job) => [
            'id' => $job->id,
            'order_number' => $job->order_number,
            'customer' => $job->customer ? ['id' => $job->customer->id, 'name' => $job->customer->name, 'phone' => $job->customer->phone] : null,
            'total_amount' => (float) $job->total_amount,
            'balance' => (float) $job->balance,
            'discount_amount' => (float) ($job->discount_amount ?? 0),
            'due_date' => $job->due_date,
            'status' => $job->status,
            'payment_status' => $job->payment_status,
        ];

        $dueTodayJobs = $dueTodayCount > 0
            ? $branchJobs()
                ->whereIn('status', $dueJobsActiveStatuses)
                ->whereDate('due_date', $today)
                ->with('customer:id,name,phone')
                ->take(20)
                ->get($dueJobFields)
                ->map($mapDueJob)
                ->values()
                ->all()
            : [];

        $dueThisWeekJobs = $dueThisWeekCount > 0
            ? $branchJobs()
                ->whereIn('status', $dueJobsActiveStatuses)
                ->whereDate('due_date', '>', $today)
                ->whereDate('due_date', '<=', $nextWeek)
                ->with('customer:id,name,phone')
                ->orderBy('due_date')
                ->take(20)
                ->get($dueJobFields)
                ->map($mapDueJob)
                ->values()
                ->all()
            : [];

        // Unclaimed pickups
        $unclaimedPickups = $branchJobs()
            ->where('status', 'ready_for_pickup')
            ->whereNotNull('ready_for_pickup_at')
            ->where('ready_for_pickup_at', '<=', now()->subDays(14))
            ->with('customer:id,name,phone')
            ->orderBy('ready_for_pickup_at')
            ->take(20)
            ->get(['id', 'order_number', 'customer_id', 'total_amount', 'balance', 'ready_for_pickup_at'])
            ->map(fn ($job) => [
                'id' => $job->id,
                'order_number' => $job->order_number,
                'customer' => $job->customer ? ['id' => $job->customer->id, 'name' => $job->customer->name, 'phone' => $job->customer->phone] : null,
                'total_amount' => (float) $job->total_amount,
                'balance' => (float) $job->balance,
                'ready_for_pickup_at' => $job->ready_for_pickup_at,
                'days_waiting' => abs((int) now()->diffInDays($job->ready_for_pickup_at)),
            ])
            ->values()
            ->all();

        // Jobs on hold 7+ days
        $jobsOnHold = $branchJobs()
            ->where('status', 'on_hold')
            ->whereNotNull('held_at')
            ->where('held_at', '<=', now()->subDays(7))
            ->with('customer:id,name,phone')
            ->orderBy('held_at')
            ->take(20)
            ->get(['id', 'order_number', 'customer_id', 'hold_reason', 'held_at'])
            ->map(fn ($job) => [
                'id' => $job->id,
                'order_number' => $job->order_number,
                'customer' => $job->customer ? ['id' => $job->customer->id, 'name' => $job->customer->name, 'phone' => $job->customer->phone] : null,
                'hold_reason' => $job->hold_reason,
                'held_at' => $job->held_at,
                'days_held' => abs((int) now()->diffInDays($job->held_at)),
            ])
            ->values()
            ->all();

        // Rejected-payments and forfeited-deposit loss figures
        $rejectedStats = Payment::whereNotNull('rejected_at')
            ->whereHas('jobOrder', function ($q) use ($store, $branchId) {
                $q->where('store_id', $store->id);
                if ($branchId) {
                    $q->where('store_branch_id', $branchId);
                }
            })
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->first();
        $rejectedPaymentsCount = (int) ($rejectedStats->count ?? 0);
        $rejectedPaymentsAmount = (float) ($rejectedStats->amount ?? 0);

        $forfeitedJobIds = $branchJobs()
            ->where('status', 'cancelled')
            ->where('cancellation_reason', 'forfeited_deposit_abandoned')
            ->pluck('id');
        $forfeitedDepositCount = $forfeitedJobIds->count();
        $forfeitedDepositAmount = $forfeitedDepositCount > 0
            ? (float) Payment::whereIn('job_order_id', $forfeitedJobIds)
                ->whereNull('rejected_at')
                ->sum('amount')
            : 0.0;

        // Today's appointments
        $todayAppointments = $branchAppointments()
            ->with(['customer:id,name', 'service:id,name'])
            ->whereDate('scheduled_at', $today)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'scheduled_at' => $a->scheduled_at,
                'appointment_type' => $a->appointment_type,
                'status' => $a->status,
                'customer' => $a->customer,
                'service' => $a->service,
            ])
            ->values()
            ->all();

        // Combined store-level auxiliary stats (1 query instead of 4)
        $storeStats = DB::selectOne('
            SELECT
                (SELECT COUNT(*) FROM services WHERE store_id = ?) as total_services,
                (SELECT COUNT(*) FROM catalog_orders WHERE store_id = ? AND (? IS NULL OR store_branch_id = ?)) as total_collections,
                (SELECT COUNT(*) FROM store_branches WHERE store_id = ?) as total_branches,
                (SELECT AVG(rating) FROM store_reviews WHERE store_id = ?) as avg_rating,
                (SELECT COUNT(*) FROM store_reviews WHERE store_id = ?) as total_reviews
        ', [$store->id, $store->id, $branchId, $branchId, $store->id, $store->id, $store->id]);

        $responsePayload = [
            'success' => true,
            'data' => [
                'total_jobs' => $totalJobs,
                'completed_jobs' => $completedJobs,
                'total_revenue' => $totalRevenue,
                'total_outstanding_balance' => $totalBalance,
                'upcoming_appointments' => $upcomingAppointments,
                'booking_conversion_rate' => $bookingConversionRate,
                'total_appointments' => $branchAppointments()->count(),
                'total_services' => (int) ($storeStats->total_services ?? 0),
                'total_collections' => (int) ($storeStats->total_collections ?? 0),
                'total_branches' => (int) ($storeStats->total_branches ?? 0),
                'total_staff' => $totalStaff,
                'total_customers' => $totalCustomers,
                'revenue_data' => $revenueData,
                'jobs_by_status' => $jobsByStatus,
                'garment_breakdown' => $garmentBreakdown,
                'recent_jobs' => $recentJobs,
                // ── New KPIs ──────────────────────────────────────────────────
                'overdue_jobs' => $overdueJobs,
                'pending_deposit_jobs' => $pendingDepositJobs,
                'ready_for_pickup_jobs' => $readyForPickupJobs,
                'rush_jobs_active' => $rushJobsActive,
                'today_revenue' => $todayRevenue,
                'completion_rate' => $completionRate,
                'avg_order_value' => $avgOrderValue,
                'avg_turnaround_days' => $avgTurnaroundDays,
                'today_appointments' => $todayAppointments,
                'outstanding_balances' => $outstandingBalances,
                'completed_unpaid_jobs' => $completedUnpaidJobs,
                'completed_unpaid_jobs_count' => $completedUnpaidJobsCount,
                'pending_dp_jobs_list' => $pendingDpJobsList,
                'pending_dp_jobs_list_count' => $pendingDpJobsCount,
                'due_today_jobs' => $dueTodayJobs,
                'due_today_jobs_count' => $dueTodayCount,
                'due_this_week_jobs' => $dueThisWeekJobs,
                'due_this_week_jobs_count' => $dueThisWeekCount,
                'unclaimed_pickups' => $unclaimedPickups,
                'jobs_on_hold' => $jobsOnHold,
                'rejected_payments_count' => $rejectedPaymentsCount,
                'rejected_payments_amount' => $rejectedPaymentsAmount,
                'forfeited_deposit_count' => $forfeitedDepositCount,
                'forfeited_deposit_amount' => $forfeitedDepositAmount,
                'avg_rating' => ! empty($storeStats->avg_rating) ? round((float) $storeStats->avg_rating, 1) : null,
                'total_reviews' => (int) ($storeStats->total_reviews ?? 0),
            ],
        ];

        if (! app()->environment('testing')) {
            Cache::driver('file')->put($cacheKey, $responsePayload, 60);
        }

        return response()->json($responsePayload);
    }

    /**
     * Objective 7's "subscription activity" reporting for store owners — the
     * current plan + real usage vs. its limits, plus the actual event
     * history (created/renewed/upgraded/downgraded/expired), not just the
     * single latest StoreSubscription row the Billing page already shows.
     */
    public function subscriptionActivity(Request $request, Store $store): JsonResponse
    {
        $current = StoreSubscription::with('plan')
            ->where('store_id', $store->id)
            ->latest()
            ->first();

        $plan = $current?->plan;

        $usage = [
            'staff' => [
                'used' => $store->staff()->count(),
                'max' => $plan?->max_staff ?? 0,
            ],
            'services' => [
                'used' => $store->services()->count(),
                'max' => $plan?->max_services ?? 0,
            ],
            'branches' => [
                // Branch Shops only — the Main Shop never counts toward the
                // plan. Same limit StoreBranchController::store enforces.
                'used' => $store->branches()->where('is_main', false)->count(),
                'max' => $plan?->branchLimit() ?? 0,
            ],
        ];

        $events = SubscriptionEvent::where('store_id', $store->id)
            ->with(['plan:id,name,slug,price_monthly', 'previousPlan:id,name,slug,price_monthly'])
            ->latest('occurred_at')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => [
                'current' => $current,
                'usage' => $usage,
                'events' => [
                    'data' => $events->items(),
                    'meta' => [
                        'current_page' => $events->currentPage(),
                        'last_page' => $events->lastPage(),
                        'total' => $events->total(),
                    ],
                ],
            ],
        ]);
    }
}
