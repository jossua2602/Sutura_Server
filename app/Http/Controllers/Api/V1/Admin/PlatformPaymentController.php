<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\StoreSubscription;
use App\Models\SubscriptionUpgradeRequest;
use App\Support\AdminAudit;
use App\Support\PlatformPayments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Payments: every GCash payment shops send SUTURA (new shop plans,
 * upgrades, renewals, downgrades). The admin checks the GCash app, then
 * approves or rejects here; the rules live in App\Support\PlatformPayments.
 */
class PlatformPaymentController extends Controller
{
    private const TZ = 'Asia/Manila';

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $query = $this->filtered($filters);

        $sortColumn = $filters['sort'] === 'amount' ? 'quoted_price' : 'created_at';
        // Default: the queue in order (oldest first) for pending, newest first otherwise.
        $dir = $filters['dir'] ?? ($filters['status'] === 'pending' ? 'asc' : 'desc');

        $page = (clone $query)
            ->with(['store:id,name,slug', 'plan:id,name', 'requester:id,name,email,contact_email', 'reviewer:id,name'])
            ->orderBy($sortColumn, $dir)->orderBy('id', $dir)
            ->paginate($filters['per_page']);

        $rows = collect($page->items())->map(fn (SubscriptionUpgradeRequest $p) => $this->row($p));

        // Per-status counts for the same search/plan/type/date filters.
        $counts = $this->filtered(['status' => ''] + $filters)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'success' => true,
            'data' => $rows,
            'counts' => $counts,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function stats(): JsonResponse
    {
        $monthStart = now(self::TZ)->startOfMonth()->utc();
        $approved = SubscriptionUpgradeRequest::where('status', 'approved');
        $rejectedOpen = SubscriptionUpgradeRequest::where('status', 'rejected')
            ->where('type', '!=', 'new_shop')
            // Not sent again yet.
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('subscription_upgrade_requests as later')
                ->whereColumn('later.resubmission_of_id', 'subscription_upgrade_requests.id'));
        $byStatus = SubscriptionUpgradeRequest::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json(['success' => true, 'data' => [
            'to_verify' => SubscriptionUpgradeRequest::where('status', 'pending')->where('type', '!=', 'new_shop')->count(),
            'with_applications' => SubscriptionUpgradeRequest::where('status', 'pending')->where('type', 'new_shop')->count(),
            'paid_this_month' => (float) (clone $approved)->where('reviewed_at', '>=', $monthStart)->sum('quoted_price'),
            'paid_this_month_count' => (clone $approved)->where('reviewed_at', '>=', $monthStart)->count(),
            'total_revenue' => (float) (clone $approved)->sum('quoted_price'),
            'rejected_open' => $rejectedOpen->count(),
            'active_subscriptions' => StoreSubscription::whereIn('status', ['active', 'trial'])
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->count(),
            'total' => (int) $byStatus->sum(),
            'paid' => (int) ($byStatus['approved'] ?? 0),
            'pending' => (int) ($byStatus['pending'] ?? 0),
        ]]);
    }

    public function show(SubscriptionUpgradeRequest $payment): JsonResponse
    {
        $payment->load(['store:id,name,slug,status', 'plan:id,name,price_monthly,price_yearly', 'requester:id,name,email,contact_email,phone',
            'reviewer:id,name', 'logs.user:id,name']);
        $current = StoreSubscription::with(['plan:id,name', 'scheduledPlan:id,name'])
            ->where('store_id', $payment->store_id)->latest()->first();

        return response()->json(['success' => true, 'data' => $this->row($payment) + [
            'logs' => $payment->logs->map(fn ($l) => [
                'id' => $l->id, 'action' => $l->action, 'note' => $l->note, 'created_at' => $l->created_at?->toISOString(),
                'by' => $l->user?->name,
            ]),
            'warnings' => $payment->warnings(),
            'store_subscription' => $current ? [
                'plan' => $current->plan?->name, 'status' => $current->status,
                'ends_at' => $current->ends_at?->toISOString(), 'scheduled_plan' => $current->scheduledPlan?->name,
            ] : null,
        ]]);
    }

    public function receipt(SubscriptionUpgradeRequest $payment): Response
    {
        $disk = Storage::disk(config('filesystems.private_disk', 'local'));
        $path = $payment->payment_receipt_path;
        if (! $path || ! $disk->exists($path)) {
            return response()->json(['success' => false, 'message' => 'Receipt not found.'], 404);
        }

        return $disk->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    public function approve(Request $request, SubscriptionUpgradeRequest $payment): JsonResponse
    {
        // The admin confirms they checked the GCash app (recorded on the timeline).
        $request->validate([
            'checked_reference' => ['accepted'],
            'checked_amount' => ['accepted'],
            'checked_sender' => ['accepted'],
        ], ['accepted' => 'Tick every item on the checklist after checking your GCash app.']);

        $message = PlatformPayments::approve($request, $payment, 'Checked in GCash: reference number, amount and sender match.');

        return response()->json(['success' => true, 'message' => $message]);
    }

    public function reject(Request $request, SubscriptionUpgradeRequest $payment): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        PlatformPayments::reject($request, $payment, trim($validated['reason']));

        return response()->json(['success' => true, 'message' => 'Payment rejected. The owner was notified and can send it again.']);
    }

    /** CSV of every row matching the current filters (not just one page). */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->filtered($filters)->with(['store:id,name', 'plan:id,name', 'requester:id,name,email,contact_email', 'reviewer:id,name'])
            ->orderBy('created_at', $filters['status'] === 'pending' ? 'asc' : 'desc')->limit(5000);

        AdminAudit::record($request, 'platform_payments_exported', SubscriptionUpgradeRequest::class, 0, 'Payments CSV');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reads ₱ / ñ correctly
            fputcsv($out, ['ID', 'Submitted', 'Type', 'Status', 'Shop', 'Owner', 'Owner email', 'Plan', 'Cycle', 'Amount due', 'Amount sent',
                'GCash ref no.', 'Sender', 'Sender number', 'Paid at (reported)', 'Period start', 'Period end', 'Reviewed by', 'Reviewed at', 'Reject reason']);
            $date = fn ($d) => $d ? Carbon::parse($d)->timezone(self::TZ)->format('Y-m-d H:i') : '';
            foreach ($query->cursor() as $p) {
                fputcsv($out, [
                    $p->id, $date($p->created_at), $p->type, $p->status === 'approved' ? 'paid' : $p->status,
                    $p->store?->name, $p->requester?->name, $p->requester?->contact_email ?? $p->requester?->email,
                    $p->plan?->name, $p->billing_cycle, $p->quoted_price, $p->amount_sent,
                    $p->payment_reference, $p->sender_name, $p->sender_number, $date($p->paid_at_reported),
                    $date($p->billing_period_start), $date($p->billing_period_end), $p->reviewer?->name, $date($p->reviewed_at), $p->rejection_reason,
                ]);
            }
            fclose($out);
        }, 'sutura-payments-'.now(self::TZ)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── GCash account shown to shop owners ─────────────────────────────────

    public function settings(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => PlatformSetting::gcash()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gcash_name' => ['required', 'string', 'max:100'],
            'gcash_number' => ['required', 'string', 'regex:/^09\d{9}$/'],
        ], ['gcash_number.regex' => 'Enter the GCash number: 11 digits starting with 09.']);

        PlatformSetting::write('gcash_name', trim($validated['gcash_name']));
        PlatformSetting::write('gcash_number', $validated['gcash_number']);
        AdminAudit::record($request, 'gcash_settings_updated', PlatformSetting::class, 0, 'GCash account');

        return response()->json(['success' => true, 'message' => 'GCash details saved. Shop owners see them right away.', 'data' => PlatformSetting::gcash()]);
    }

    public function uploadQr(Request $request): JsonResponse
    {
        $request->validate(['qr' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $old = PlatformSetting::read('gcash_qr_path');
        // Public on purpose: owners scan it to pay.
        $path = $request->file('qr')->store('platform', 'public');
        PlatformSetting::write('gcash_qr_path', $path);
        if ($old) {
            Storage::disk('public')->delete($old);
        }
        AdminAudit::record($request, 'gcash_settings_updated', PlatformSetting::class, 0, 'GCash QR code');

        return response()->json(['success' => true, 'message' => 'QR code saved.', 'data' => PlatformSetting::gcash()]);
    }

    public function deleteQr(Request $request): JsonResponse
    {
        if ($old = PlatformSetting::read('gcash_qr_path')) {
            Storage::disk('public')->delete($old);
        }
        PlatformSetting::write('gcash_qr_path', null);
        AdminAudit::record($request, 'gcash_settings_updated', PlatformSetting::class, 0, 'GCash QR code removed');

        return response()->json(['success' => true, 'message' => 'QR code removed.', 'data' => PlatformSetting::gcash()]);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function filters(Request $request): array
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'plan_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'in:'.implode(',', SubscriptionUpgradeRequest::TYPES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', 'in:date,amount'],
            'dir' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        return [
            'q' => trim((string) ($v['q'] ?? '')), 'status' => $v['status'] ?? '', 'plan_id' => $v['plan_id'] ?? null,
            'type' => $v['type'] ?? null, 'from' => $v['from'] ?? null, 'to' => $v['to'] ?? null,
            'sort' => $v['sort'] ?? 'date', 'dir' => $v['dir'] ?? null,
            'per_page' => (int) ($v['per_page'] ?? 5),
        ];
    }

    private function filtered(array $f): Builder
    {
        return SubscriptionUpgradeRequest::query()
            ->when($f['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($f['plan_id'], fn ($q, $id) => $q->where('plan_id', $id))
            ->when($f['type'], fn ($q, $t) => $q->where('type', $t))
            ->when($f['from'], fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d, self::TZ)->startOfDay()->utc()))
            ->when($f['to'], fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d, self::TZ)->endOfDay()->utc()))
            ->when($f['q'] !== '', function ($q) use ($f) {
                $like = '%'.mb_strtolower($f['q']).'%';
                $key = SubscriptionUpgradeRequest::referenceKey($f['q']);
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(sender_name) LIKE ?', [$like])
                    ->when($key, fn ($k) => $k->orWhere('reference_key', 'like', '%'.$key.'%'))
                    ->orWhereHas('store', fn ($s) => $s->whereRaw('LOWER(name) LIKE ?', [$like]))
                    ->orWhereHas('requester', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like])->orWhereRaw('LOWER(contact_email) LIKE ?', [$like])));
            });
    }

    private function row(SubscriptionUpgradeRequest $p): array
    {
        return [
            'id' => $p->id,
            'type' => $p->type,
            'status' => $p->status,
            'billing_cycle' => $p->billing_cycle,
            'amount_due' => (float) $p->quoted_price,
            'amount_sent' => $p->amount_sent !== null ? (float) $p->amount_sent : null,
            'payment_reference' => $p->payment_reference,
            'sender_name' => $p->sender_name,
            'sender_number' => $p->sender_number,
            'paid_at_reported' => $p->paid_at_reported?->toISOString(),
            'has_receipt' => (bool) $p->payment_receipt_path,
            'rejection_reason' => $p->rejection_reason,
            'billing_period_start' => $p->billing_period_start?->toISOString(),
            'billing_period_end' => $p->billing_period_end?->toISOString(),
            'created_at' => $p->created_at?->toISOString(),
            'reviewed_at' => $p->reviewed_at?->toISOString(),
            'resubmission_of_id' => $p->resubmission_of_id,
            'warning_count' => count($p->warnings()),
            'store' => $p->store ? ['id' => $p->store->id, 'name' => $p->store->name] : null,
            'owner' => $p->requester ? ['name' => $p->requester->name, 'email' => $p->requester->contact_email ?? $p->requester->email, 'phone' => $p->requester->phone ?? null] : null,
            'plan' => $p->plan ? ['id' => $p->plan->id, 'name' => $p->plan->name] : null,
            'reviewer' => $p->reviewer?->name,
        ];
    }
}
