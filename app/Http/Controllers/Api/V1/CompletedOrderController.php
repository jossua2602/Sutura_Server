<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Support\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Completed Orders page: job orders with status = completed only, scoped
 * to the branch the dashboard has selected (BranchScope). The summary cards
 * are their own unbounded queries, never derived from the paginated list.
 */
class CompletedOrderController extends Controller
{
    private const MAX_EXPORT = 5000;

    private const RELATIONS = [
        'customer:id,name,profile_picture',
        'service:id,name',
        'catalogItem:id,name',
        'branch:id,name',
    ];

    public function index(Request $request, Store $store): JsonResponse
    {
        $request->validate($this->rules() + ['per_page' => ['sometimes', 'integer', 'min:5', 'max:100']]);
        $scope = $this->scoped($request, $store);

        $list = $this->filtered($request, clone $scope)
            ->with(self::RELATIONS)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $list,
            'stats' => $this->stats($scope),
        ]);
    }

    public function export(Request $request, Store $store): StreamedResponse|JsonResponse
    {
        $request->validate($this->rules());
        $query = $this->filtered($request, $this->scoped($request, $store));

        $count = (clone $query)->count();
        if ($count === 0) {
            return response()->json(['success' => false, 'message' => 'There are no completed orders to export with these filters.'], 422);
        }
        if ($count > self::MAX_EXPORT) {
            return response()->json(['success' => false, 'message' => 'That is more than '.self::MAX_EXPORT.' orders. Narrow the dates and try again.'], 422);
        }

        $name = 'SUTURA-'.Str::slug($store->name).'-completed-orders-'.now()->toDateString().'.csv';
        $rows = $query->with(self::RELATIONS)->orderByDesc('completed_at')->orderByDesc('id');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel opens the UTF-8 file correctly
            fputcsv($out, ['Order ID', 'Customer', 'Product / Service', 'Branch', 'Order date', 'Completion date', 'Price (PHP)', 'Discount (PHP)', 'Payment', 'Status']);
            $rows->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $job) {
                    fputcsv($out, [
                        $job->order_number,
                        $job->customer?->name,
                        $job->catalogItem?->name ?? $job->servicePackage?->name ?? $job->service?->name,
                        $job->branch?->name,
                        $job->created_at?->format('Y-m-d'),
                        $job->completed_at?->format('Y-m-d'),
                        number_format((float) $job->total_amount, 2, '.', ''),
                        number_format((float) $job->discount_amount, 2, '.', ''),
                        $job->payment_status,
                        'Completed',
                    ]);
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'payment_status' => ['sometimes', 'nullable', 'in:paid,partial,unpaid'],
            'intake_channel' => ['sometimes', 'nullable', 'in:walk_in,online'],
        ];
    }

    /** Completed jobs of this store, in the branch the request may see. */
    private function scoped(Request $request, Store $store): Builder
    {
        $query = $store->jobOrders()->getQuery()->where('status', 'completed');
        if (($branchId = BranchScope::resolve($request, $store)) !== null) {
            $query->where('store_branch_id', $branchId);
        }

        return $query;
    }

    /** The toolbar's search box and Filter panel (the summary cards ignore these). */
    private function filtered(Request $request, Builder $query): Builder
    {
        if ($request->filled('search')) {
            $search = '%'.strtolower((string) $request->string('search')).'%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(order_number) LIKE ?', [$search])
                    ->orWhereHas('customer', fn ($cq) => $cq->whereRaw('LOWER(name) LIKE ?', [$search]));
            });
        }
        if ($request->filled('from')) {
            $query->where('completed_at', '>=', $request->date('from')->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('completed_at', '<=', $request->date('to')->endOfDay());
        }
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->input('payment_status'));
        }
        if ($request->filled('intake_channel')) {
            $query->where('intake_channel', $request->input('intake_channel'));
        }

        return $query;
    }

    /**
     * Trends compare the last 30 days with the 30 days before them (null
     * when there is nothing to compare against). Value is what the customer
     * owed after any discount (total_amount - discount_amount).
     */
    private function stats(Builder $scope): array
    {
        $now = now();
        $window = fn (int $daysAgoStart, int $daysAgoEnd) => (clone $scope)
            ->where('completed_at', '>', $now->copy()->subDays($daysAgoStart))
            ->where('completed_at', '<=', $now->copy()->subDays($daysAgoEnd));
        $value = fn (Builder $q) => (float) $q->sum(DB::raw('total_amount - COALESCE(discount_amount, 0)'));
        $trend = fn (float $current, float $previous) => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;

        $countNow = $window(30, 0)->count();
        $countBefore = $window(60, 30)->count();
        $valueNow = $value($window(30, 0));
        $valueBefore = $value($window(60, 30));

        $seconds = match (DB::connection()->getDriverName()) {
            'pgsql' => 'EXTRACT(EPOCH FROM (completed_at - created_at))',
            'sqlite' => '(julianday(completed_at) - julianday(created_at)) * 86400',
            default => 'TIMESTAMPDIFF(SECOND, created_at, completed_at)',
        };
        $avgSeconds = (clone $scope)->whereNotNull('completed_at')->avg(DB::raw($seconds));

        return [
            'total_completed' => (clone $scope)->count(),
            'total_completed_trend' => $trend($countNow, $countBefore),
            'total_value' => round($value(clone $scope), 2),
            'total_value_trend' => $trend($valueNow, $valueBefore),
            'completed_today' => (clone $scope)
                ->whereBetween('completed_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
                ->count(),
            'avg_handling_days' => $avgSeconds !== null ? round((float) $avgSeconds / 86400, 1) : null,
        ];
    }
}
