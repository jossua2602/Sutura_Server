<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payments → Cash: every order payment taken in cash, for counting the drawer.
 * Owner and branch manager only (money totals); a manager only sees their own branch.
 * Rejected payments are left out. Totals are their own queries, never derived from the
 * capped row list (see "capped-list counts" in CLAUDE.md).
 */
class CashPaymentController extends Controller
{
    private const PER_PAGE = 7;

    private const MAX_EXPORT = 5000;

    private const ROW_RELATIONS = [
        'jobOrder:id,order_number,payment_status,customer_id,store_branch_id,service_id,total_amount,balance,discount_amount,status',
        'jobOrder.customer:id,name', 'jobOrder.branch:id,name', 'jobOrder.service:id,name', 'recordedBy:id,name',
    ];

    private function branchScope(Request $request): ?int
    {
        $user = $request->user();
        if (! $user->hasRole('store_owner') && $user->staffProfile?->store_branch_id) {
            return (int) $user->staffProfile->store_branch_id;
        }

        return $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
    }

    /** Cash payments of this shop (and branch, when scoped), not rejected. */
    private function base(Store $store, ?int $branchId): Builder
    {
        return Payment::query()
            ->where('payment_method', 'cash')
            ->whereNull('rejected_at')
            ->whereHas('jobOrder', function ($q) use ($store, $branchId) {
                $q->where('store_id', $store->id);
                if ($branchId) {
                    $q->where('store_branch_id', $branchId);
                }
            });
    }

    /** [from, to] for a period, in the shop's time (app timezone). Null = all time. */
    private function range(string $period): ?array
    {
        $now = Carbon::now();

        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(Carbon::MONDAY), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            default => null,
        };
    }

    private function filtered(Request $request, Store $store): Builder
    {
        $query = $this->base($store, $this->branchScope($request));
        if ($range = $this->range((string) $request->input('period', 'month'))) {
            $query->whereBetween('created_at', $range);
        }
        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            // Grouped so the OR stays inside this order's match.
            $query->whereHas('jobOrder', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(order_number) LIKE ?', [$like])
                ->orWhereHas('customer', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$like]))));
        }

        return $query;
    }

    private function sumFor(Store $store, ?int $branchId, string $period): array
    {
        $q = $this->base($store, $branchId)->whereBetween('created_at', $this->range($period));

        return ['amount' => round((float) (clone $q)->sum('amount'), 2), 'count' => (clone $q)->count()];
    }

    private function present(Payment $p): array
    {
        $job = $p->jobOrder;

        return [
            'id' => $p->id,
            'paid_at' => $p->created_at?->toIso8601String(),
            'job_order_id' => $job?->id,
            'order_number' => $job?->order_number,
            'order_payment_status' => $job?->payment_status,
            'customer' => $job?->customer?->name,
            'type' => $p->type,
            'amount' => (float) $p->amount,
            'cash_tendered' => $p->cash_tendered !== null ? (float) $p->cash_tendered : null,
            'change_amount' => $p->change_amount !== null ? (float) $p->change_amount : null,
            'received_by' => $p->recordedBy?->name,
            'branch' => $job?->branch?->name,
            'notes' => $p->notes,
            // For the payment's detail pop-up: where the order stands now.
            'order' => $job ? [
                'service' => $job->service?->name,
                'status' => $job->status,
                'total' => (float) $job->total_amount,
                // Paid so far = total − balance − discount (a discount is never cash collected).
                'paid' => round((float) $job->total_amount - (float) $job->balance - (float) $job->discount_amount, 2),
                'balance' => (float) $job->balance,
                'discount' => (float) $job->discount_amount,
            ] : null,
        ];
    }

    public function index(Request $request, Store $store): JsonResponse
    {
        $request->validate([
            'period' => ['nullable', 'in:today,week,month,all'],
            'q' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $branchId = $this->branchScope($request);
        $query = $this->filtered($request, $store);

        $page = (clone $query)
            ->with(self::ROW_RELATIONS)
            ->latest('created_at')->latest('id')
            ->paginate((int) $request->input('per_page', self::PER_PAGE));

        // Cash by staff, this month: who took how much, for matching each person's collections.
        $byStaff = $this->base($store, $branchId)
            ->whereBetween('created_at', $this->range('month'))
            ->selectRaw('recorded_by, SUM(amount) as total, COUNT(*) as payments')
            ->groupBy('recorded_by')->orderByDesc('total')
            ->with('recordedBy:id,name')->get()
            ->map(fn ($r) => ['name' => $r->recordedBy?->name ?? 'Unknown', 'amount' => round((float) $r->total, 2), 'count' => (int) $r->payments])
            ->values();

        return response()->json(['success' => true, 'data' => [
            'rows' => collect($page->items())->map(fn ($p) => $this->present($p))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'period_total' => ['amount' => round((float) (clone $query)->sum('amount'), 2), 'count' => (clone $query)->count()],
            'today' => $this->sumFor($store, $branchId, 'today'),
            'week' => $this->sumFor($store, $branchId, 'week'),
            'month' => $this->sumFor($store, $branchId, 'month'),
            'by_staff' => $byStaff,
        ]]);
    }

    /** CSV of every cash payment matching the filters (not just the rows on screen). */
    public function export(Request $request, Store $store): StreamedResponse
    {
        $request->validate([
            'period' => ['nullable', 'in:today,week,month,all'],
            'q' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        $query = $this->filtered($request, $store)
            ->with(self::ROW_RELATIONS)
            ->latest('created_at')->limit(self::MAX_EXPORT);
        $period = (string) $request->input('period', 'month');
        $name = 'cash-payments-'.$period.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reads ₱ / ñ correctly
            fputcsv($out, ['Date', 'Time', 'Order no.', 'Customer', 'Payment', 'Amount (PHP)', 'Cash given', 'Change', 'Received by', 'Branch', 'Order status']);
            foreach ($query->cursor() as $p) {
                $r = $this->present($p);
                $at = $p->created_at;
                fputcsv($out, [
                    $at?->format('Y-m-d'), $at?->format('H:i'), $r['order_number'], $r['customer'], ucfirst((string) $r['type']),
                    number_format($r['amount'], 2, '.', ''),
                    $r['cash_tendered'] !== null ? number_format($r['cash_tendered'], 2, '.', '') : '',
                    $r['change_amount'] !== null ? number_format($r['change_amount'], 2, '.', '') : '',
                    $r['received_by'], $r['branch'], $r['order_payment_status'],
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
