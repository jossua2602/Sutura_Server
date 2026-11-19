<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogOrder;
use App\Models\JobOrder;
use App\Models\Store;
use App\Models\StoreApplication;
use App\Models\StoreSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\SupportTicket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * System Admin overview + subscription report (sutura2's dashboard and
 * SubscriptionReportView). Every figure is its own uncapped query.
 */
class DashboardController extends Controller
{
    public function overview(): JsonResponse
    {
        $roleCounts = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->selectRaw('roles.name, COUNT(DISTINCT role_user.user_id) as total')
            ->groupBy('roles.name')
            ->pluck('total', 'name');

        return response()->json([
            'success' => true,
            'data' => [
                'users' => [
                    'total' => User::count(),
                    'customers' => (int) ($roleCounts['customer'] ?? 0),
                    'store_owners' => (int) ($roleCounts['store_owner'] ?? 0),
                    'suspended' => User::whereNotNull('suspended_at')->count(),
                ],
                'stores' => Store::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
                'active_subscriptions' => $this->activeSubscriptions()->count(),
                'pending_branches' => \App\Models\StoreBranch::where('verification_status', 'pending')->whereHas('store', fn ($q) => $q->where('status', 'approved'))->count(),
                'pending_apparel_types' => \App\Models\CustomApparelType::where('status', 'pending')->count(),
                'open_tickets' => SupportTicket::whereIn('status', ['open', 'in_progress'])->count(),
                'recent_applications' => Store::with('owner:id,name,email,contact_email')
                    ->where('status', 'pending')
                    ->oldest()
                    ->take(5)
                    ->get(['id', 'name', 'slug', 'owner_id', 'city', 'created_at']),
            ],
        ]);
    }

    public function subscriptionReport(Request $request): JsonResponse
    {
        [$start, $end, $monthStarts] = $this->reportWindow($request);
        $byPlan = $this->activeSubscriptions()
            ->join('subscription_plans', 'subscription_plans.id', '=', 'store_subscriptions.plan_id')
            ->selectRaw('subscription_plans.id, subscription_plans.name, subscription_plans.price_monthly, COUNT(*) as stores')
            ->groupBy('subscription_plans.id', 'subscription_plans.name', 'subscription_plans.price_monthly')
            ->orderBy('subscription_plans.price_monthly')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'plan' => $row->name,
                'stores' => (int) $row->stores,
                // List price × active stores — an estimate, since yearly
                // subscribers actually pay the discounted yearly rate.
                'estimated_mrr' => round((float) $row->price_monthly * (int) $row->stores, 2),
            ]);

        $started = StoreSubscription::whereBetween('starts_at', [$start, $end])->get(['starts_at'])
            ->groupBy(fn ($s) => $s->starts_at->format('Y-m'));
        // Decision timestamps, not updated_at — any profile edit bumps that.
        $approvedByMonth = Store::whereBetween('approved_at', [$start, $end])->get(['approved_at'])
            ->groupBy(fn ($s) => $s->approved_at->format('Y-m'));
        $rejectedByMonth = StoreApplication::whereBetween('reviewed_at', [$start, $end])
            ->whereHas('store', fn ($q) => $q->where('status', 'rejected'))
            ->get(['reviewed_at'])
            ->groupBy(fn ($a) => $a->reviewed_at->format('Y-m'));

        // Plan payments the admin approved — the subscription money the
        // platform actually received, dated by the approval, not the request.
        $planPayments = SubscriptionUpgradeRequest::with('plan:id,name')
            ->inLedger()
            ->where('status', 'approved')
            ->whereBetween('reviewed_at', [$start, $end])
            ->get(['id', 'plan_id', 'billing_cycle', 'quoted_price', 'refund_amount', 'refund_status', 'reviewed_at']);
        $paymentsByMonth = $planPayments->groupBy(fn ($p) => $p->reviewed_at->format('Y-m'));

        $months = $monthStarts->map(function (Carbon $month) use ($started, $approvedByMonth, $rejectedByMonth, $paymentsByMonth) {
            $key = $month->format('Y-m');
            $paid = $paymentsByMonth->get($key, collect());

            return [
                'label' => $month->format('M Y'),
                'new_subscriptions' => $started->get($key, collect())->count(),
                'approved' => $approvedByMonth->get($key, collect())->count(),
                'rejected' => $rejectedByMonth->get($key, collect())->count(),
                'plan_payments' => $paid->count(),
                'plan_income' => round((float) $paid->sum(fn ($p) => $p->netAmount()), 2),
            ];
        });

        $statusCounts = Store::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $approved = (int) ($statusCounts['approved'] ?? 0);
        $rejected = (int) ($statusCounts['rejected'] ?? 0);

        // Each live store's current subscription (its latest row).
        $subscriptionStatus = ['paid' => 0, 'trial' => 0, 'lapsed' => 0, 'none' => 0];
        Store::where('status', 'approved')->with('subscription')->get(['id'])->each(function (Store $store) use (&$subscriptionStatus) {
            $sub = $store->subscription;
            $current = $sub && ($sub->ends_at === null || $sub->ends_at->isFuture());
            $bucket = match (true) {
                ! $sub => 'none',
                $current && $sub->status === 'active' => 'paid',
                $current && $sub->status === 'trial' => 'trial',
                default => 'lapsed',
            };
            $subscriptionStatus[$bucket]++;
        });

        // Waiting for an admin on Payments (a new shop's is decided with its application).
        $pending = SubscriptionUpgradeRequest::inLedger()->where('status', 'pending')->where('type', '!=', 'new_shop');

        return response()->json([
            'success' => true,
            'data' => [
                'by_plan' => $byPlan,
                'estimated_mrr' => round($byPlan->sum('estimated_mrr'), 2),
                'months' => $months,
                'approval_rate' => $approved + $rejected > 0 ? round($approved / ($approved + $rejected) * 100, 1) : null,
                'subscription_status' => $subscriptionStatus,
                'plan_income' => round((float) $planPayments->sum(fn ($p) => $p->netAmount()), 2),
                'plan_payments' => $planPayments->count(),
                'pending_plan_payments' => (clone $pending)->count(),
                'pending_plan_income' => round((float) (clone $pending)->sum('quoted_price'), 2),
                'generated_at' => now(),
            ],
        ]);
    }

    /**
     * The live stores on one plan (Reports → "Live stores by plan"), filtered
     * by billing cycle. Same "live" definition as by_plan, so the list always
     * matches the count it was opened from.
     */
    public function planStores(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $cycle = $request->input('cycle', 'all');
        $base = $this->activeSubscriptions()->where('plan_id', $plan->id);

        $subscriptions = (clone $base)
            ->when($cycle === 'monthly' || $cycle === 'yearly', fn ($q) => $q->where('billing_cycle', $cycle))
            ->with(['store:id,name,slug,city,owner_id,status', 'store.owner:id,name,email,contact_email'])
            ->orderBy('ends_at')
            ->get()
            ->map(fn (StoreSubscription $sub) => [
                'id' => $sub->id,
                'store' => $sub->store ? [
                    'id' => $sub->store->id,
                    'name' => $sub->store->name,
                    'slug' => $sub->store->slug,
                    'city' => $sub->store->city,
                ] : null,
                'owner' => $sub->store?->owner ? [
                    'name' => $sub->store->owner->name,
                    'email' => $sub->store->owner->contact_email ?: $sub->store->owner->email,
                ] : null,
                'status' => $sub->status, // active | trial
                'billing_cycle' => $sub->billing_cycle, // null on a trial
                'starts_at' => $sub->starts_at,
                'ends_at' => $sub->ends_at,
                'days_left' => $sub->ends_at ? max(0, (int) ceil(now()->diffInSeconds($sub->ends_at, false) / 86400)) : null,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'plan' => ['id' => $plan->id, 'name' => $plan->name, 'price_monthly' => $plan->price_monthly, 'price_yearly' => $plan->price_yearly],
                'counts' => [
                    'all' => (clone $base)->count(),
                    'monthly' => (clone $base)->where('billing_cycle', 'monthly')->count(),
                    'yearly' => (clone $base)->where('billing_cycle', 'yearly')->count(),
                    'trial' => (clone $base)->where('status', 'trial')->count(),
                ],
                'stores' => $subscriptions,
            ],
        ]);
    }

    /**
     * Shop revenue across the platform: collected sales per month, outstanding
     * customer balances, and each live shop's revenue and productivity.
     * Order revenue uses the same formula as the shop's own dashboard
     * (total − balance − discount — a discount lowers balance, never total),
     * dated by order creation, so a shop sees the same figure the admin does.
     */
    public function revenueReport(Request $request): JsonResponse
    {
        [$start, $end, $monthStarts] = $this->reportWindow($request);

        $jobs = JobOrder::whereBetween('created_at', [$start, $end])
            ->get(['id', 'store_id', 'status', 'total_amount', 'balance', 'discount_amount', 'created_at']);
        $catalogSales = CatalogOrder::whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->get(['id', 'store_id', 'total_amount', 'created_at']);
        $collected = fn (JobOrder $job) => max(0.0, (float) $job->total_amount - (float) $job->balance - (float) $job->discount_amount);

        $jobsByMonth = $jobs->groupBy(fn ($j) => $j->created_at->format('Y-m'));
        $salesByMonth = $catalogSales->groupBy(fn ($o) => $o->created_at->format('Y-m'));
        $months = $monthStarts->map(function (Carbon $month) use ($jobsByMonth, $salesByMonth, $collected) {
            $key = $month->format('Y-m');
            $orderRevenue = round((float) $jobsByMonth->get($key, collect())->sum($collected), 2);
            $catalogRevenue = round((float) $salesByMonth->get($key, collect())->sum('total_amount'), 2);

            return [
                'label' => $month->format('M Y'),
                'order_revenue' => $orderRevenue,
                'catalog_revenue' => $catalogRevenue,
                'revenue' => round($orderRevenue + $catalogRevenue, 2),
                'orders' => $jobsByMonth->get($key, collect())->count(),
            ];
        });

        // Outstanding balances are what customers owe right now, so they are
        // not limited to the selected period.
        $outstanding = JobOrder::whereNotIn('status', ['cancelled', 'rejected'])
            ->where('balance', '>', 0)
            ->selectRaw('store_id, COUNT(*) as orders, COALESCE(SUM(balance), 0) as owed')
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        $jobsByStore = $jobs->groupBy('store_id');
        $salesByStore = $catalogSales->groupBy('store_id');
        $stores = Store::where('status', 'approved')
            ->with(['subscription.plan:id,name', 'owner:id,name'])
            ->get(['id', 'name', 'slug', 'city', 'owner_id'])
            ->map(function (Store $store) use ($jobsByStore, $salesByStore, $outstanding, $collected) {
                $storeJobs = $jobsByStore->get($store->id, collect());
                $orderRevenue = round((float) $storeJobs->sum($collected), 2);
                $catalogRevenue = round((float) $salesByStore->get($store->id, collect())->sum('total_amount'), 2);
                $orders = $storeJobs->count();
                $completed = $storeJobs->where('status', 'completed')->count();

                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'city' => $store->city,
                    'owner' => $store->owner?->name,
                    'plan' => $store->subscription?->plan?->name,
                    'order_revenue' => $orderRevenue,
                    'catalog_revenue' => $catalogRevenue,
                    'revenue' => round($orderRevenue + $catalogRevenue, 2),
                    'outstanding' => round((float) ($outstanding->get($store->id)->owed ?? 0), 2),
                    'outstanding_orders' => (int) ($outstanding->get($store->id)->orders ?? 0),
                    'orders' => $orders,
                    'completed' => $completed,
                    'completion_rate' => $orders > 0 ? round($completed / $orders * 100, 1) : null,
                ];
            })
            ->sortByDesc('revenue')
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'totals' => [
                    'revenue' => round($months->sum('revenue'), 2),
                    'order_revenue' => round($months->sum('order_revenue'), 2),
                    'catalog_revenue' => round($months->sum('catalog_revenue'), 2),
                    'outstanding' => round((float) $outstanding->sum('owed'), 2),
                    'outstanding_orders' => (int) $outstanding->sum('orders'),
                    'orders' => $jobs->count(),
                    'completed' => $jobs->where('status', 'completed')->count(),
                ],
                'months' => $months,
                'stores' => $stores,
                'generated_at' => now(),
            ],
        ]);
    }

    /** Report period in months: 3, 6 or 12 (default 6). */
    private function monthCount(Request $request): int
    {
        $months = (int) $request->query('months', 6);

        return in_array($months, [3, 6, 12], true) ? $months : 6;
    }

    /**
     * The report window: ?from=YYYY-MM-DD&to=YYYY-MM-DD (a custom range, at most
     * 24 months), else the last 3/6/12 months up to now. Returns the window and
     * the first day of each month it touches, for the per-month charts.
     *
     * @return array{0: Carbon, 1: Carbon, 2: \Illuminate\Support\Collection<int, Carbon>}
     */
    private function reportWindow(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $valid = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
        if ($valid($from) && $valid($to)) {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->endOfDay();
            if ($end->gte($start) && $start->copy()->addMonths(24)->gte($end)) {
                $months = collect();
                for ($m = $start->copy()->startOfMonth(); $m->lte($end); $m->addMonth()) {
                    $months->push($m->copy());
                }

                return [$start, $end, $months];
            }
        }
        $count = $this->monthCount($request);
        $start = Carbon::now()->startOfMonth()->subMonths($count - 1);

        return [$start, Carbon::now()->endOfDay(), collect(range(0, $count - 1))->map(fn (int $i) => $start->copy()->addMonths($i))];
    }

    private function activeSubscriptions()
    {
        return StoreSubscription::query()
            ->whereIn('store_subscriptions.status', ['active', 'trial'])
            ->where(fn ($q) => $q->whereNull('store_subscriptions.ends_at')->orWhere('store_subscriptions.ends_at', '>', now()));
    }
}
