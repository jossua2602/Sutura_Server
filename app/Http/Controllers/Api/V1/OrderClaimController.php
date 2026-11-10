<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\OrderClaim;
use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\StoreActivityNotification;
use App\Support\OrderNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * After-sale claims ("Report a problem"). Within OrderClaim::WINDOW_DAYS of an
 * order being completed, the customer (or the shop, for a walk-in at the
 * counter) reports a problem with photos and chooses repair or refund. The
 * owner/branch manager accepts or declines (with a reason):
 *  - repair  → a linked ₱0 "warranty repair" job order goes through production;
 *              when it is completed the claim is resolved and a new window opens.
 *  - refund  → the garment must be returned, then the shop records the refund
 *              (amount up to what was paid, cash or GCash). SUTURA never moves money.
 */
class OrderClaimController extends Controller
{
    private const REASON_LABELS = [
        'poor_stitching' => 'Poor stitching',
        'wrong_fit' => 'Wrong fit',
        'damaged' => 'Damaged',
        'other' => 'Other',
    ];

    // ── Customer ────────────────────────────────────────────────────────────

    public function customerIndex(Request $request, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->payload($jobOrder, false)]);
    }

    public function customerStore(Request $request, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        return $this->file($request, $jobOrder, false);
    }

    /** A declined customer asks SUTURA support to review the shop's decision (once per claim). */
    public function escalate(Request $request, JobOrder $jobOrder, OrderClaim $claim): JsonResponse
    {
        if ($jobOrder->customer_id !== $request->user()->id || $claim->job_order_id !== $jobOrder->id) {
            return response()->json(['success' => false, 'message' => 'Claim not found.'], 404);
        }
        if ($claim->status !== 'declined') {
            return response()->json(['success' => false, 'message' => 'Only a declined claim can be sent to SUTURA support.'], 422);
        }
        if ($claim->escalated_ticket_id) {
            return response()->json(['success' => false, 'message' => 'You already asked SUTURA support to review this claim.'], 422);
        }
        $validated = $request->validate(['message' => ['required', 'string', 'min:10', 'max:2000']], [
            'message.min' => 'Tell SUTURA support why you disagree (at least 10 characters).',
        ]);

        $what = $claim->type === 'refund' ? 'refund' : 'repair';
        $ticket = SupportTicket::create([
            'store_id' => $jobOrder->store_id,
            'user_id' => $request->user()->id,
            'subject' => "Declined {$what} claim: {$jobOrder->order_number}",
            'message' => "The shop declined my {$what} claim on {$jobOrder->order_number} (".(self::REASON_LABELS[$claim->reason] ?? $claim->reason).").\n\n"
                ."My report: {$claim->description}\n\nShop's reason: {$claim->decline_reason}\n\nWhy I disagree: {$validated['message']}",
            'attachments' => $claim->photos,
            'type' => 'problem',
            'priority' => 'medium',
            'status' => 'open',
        ]);
        $claim->update(['escalated_ticket_id' => $ticket->id]);

        return response()->json([
            'success' => true,
            'message' => 'Sent to SUTURA support. You can follow it under Support Tickets.',
            'data' => $claim->fresh()->toClientArray(false),
        ]);
    }

    // ── Shop ────────────────────────────────────────────────────────────────

    public function shopIndex(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderDenied($request, $store, $jobOrder)) {
            return $denied;
        }

        return response()->json(['success' => true, 'data' => $this->payload($jobOrder, true)]);
    }

    /** Filed by the shop for a customer at the counter (walk-ins without an account). */
    public function shopStore(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderDenied($request, $store, $jobOrder)) {
            return $denied;
        }

        return $this->file($request, $jobOrder, true);
    }

    public function accept(Request $request, Store $store, OrderClaim $claim): JsonResponse
    {
        $order = $claim->jobOrder;
        if ($denied = $this->claimDenied($request, $store, $claim)) {
            return $denied;
        }
        if ($claim->status !== 'submitted') {
            return response()->json(['success' => false, 'message' => $claim->status === 'repair_offered' ? 'Waiting for the customer to answer the repair offer.' : 'This claim has already been decided.'], 422);
        }

        if ($claim->type === 'refund') {
            $claim->update(['status' => 'awaiting_return', 'decided_by_id' => $request->user()->id, 'decided_at' => now()]);
            $this->notifyCustomer($claim, 'claim_accepted', 'Refund Accepted',
                "{$store->name} accepted your refund request for {$order->order_number}. Please return the garment to the shop to receive your refund.");

            return response()->json(['success' => true, 'message' => 'Refund accepted. Record it once the garment is returned.', 'data' => $claim->fresh()->toClientArray(true)]);
        }

        // Repair: same details a walk-in order is created with — due date required, staff optional.
        [$dueDate, $staffId] = $this->repairDetails($request, $store, $order);
        $repair = $this->startRepair($store, $order, $claim, $dueDate, $staffId, $request->user()->id);

        $this->notifyCustomer($claim, 'claim_accepted', 'Repair Accepted',
            "{$store->name} will repair your order {$order->order_number} for free. Track it as {$repair->order_number}.");

        return response()->json([
            'success' => true,
            'message' => "Repair accepted — {$repair->order_number} was added to production.",
            'data' => $claim->fresh('repairJobOrder')->toClientArray(true),
        ]);
    }

    /**
     * On a refund claim the shop can offer a free repair instead (once). Nothing is
     * created yet — the customer chooses: take the repair, or keep the refund request.
     */
    public function offerRepair(Request $request, Store $store, OrderClaim $claim): JsonResponse
    {
        $order = $claim->jobOrder;
        if ($denied = $this->claimDenied($request, $store, $claim)) {
            return $denied;
        }
        if ($claim->status !== 'submitted' || $claim->type !== 'refund') {
            return response()->json(['success' => false, 'message' => 'A repair can only be offered on a refund claim that is waiting for a decision.'], 422);
        }
        if ($claim->repair_offered_at) {
            return response()->json(['success' => false, 'message' => 'A repair was already offered on this claim.'], 422);
        }
        [$dueDate, $staffId] = $this->repairDetails($request, $store, $order);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        $claim->update([
            'status' => 'repair_offered',
            'offered_due_date' => $dueDate,
            'offered_staff_id' => $staffId,
            'offer_note' => $note,
            'repair_offered_at' => now(),
        ]);
        $this->notifyCustomer($claim, 'claim_repair_offered', 'Free Repair Offered',
            "{$store->name} offered to repair {$order->order_number} for free instead of a refund. Open your order to accept the repair or keep your refund request.");

        return response()->json(['success' => true, 'message' => 'Repair offered. The customer will choose.', 'data' => $claim->fresh()->toClientArray(true)]);
    }

    /** The customer answers a repair offer. */
    public function customerRespond(Request $request, JobOrder $jobOrder, OrderClaim $claim): JsonResponse
    {
        if ($jobOrder->customer_id !== $request->user()->id || $claim->job_order_id !== $jobOrder->id) {
            return response()->json(['success' => false, 'message' => 'Claim not found.'], 404);
        }

        return $this->respond($request, $claim, false);
    }

    /** The shop records the customer's answer at the counter (walk-ins without an account). */
    public function shopRespond(Request $request, Store $store, OrderClaim $claim): JsonResponse
    {
        if ($denied = $this->claimDenied($request, $store, $claim)) {
            return $denied;
        }

        return $this->respond($request, $claim, true);
    }

    private function respond(Request $request, OrderClaim $claim, bool $byShop): JsonResponse
    {
        if ($claim->status !== 'repair_offered') {
            return response()->json(['success' => false, 'message' => 'There is no repair offer waiting for an answer.'], 422);
        }
        $choice = $request->validate(['choice' => ['required', Rule::in(['repair', 'refund'])]])['choice'];
        $order = $claim->jobOrder;
        $store = $order->store;

        if ($choice === 'refund') {
            // Back to the shop: it now accepts or declines the refund (no second offer).
            $claim->update(['status' => 'submitted', 'offer_declined_at' => now()]);
            if (! $byShop) {
                $this->notifyShop($request, $order, $claim, false, 'claim_offer_declined', 'Repair Offer Declined',
                    ($order->customer?->name ?? 'The customer')." wants a refund instead of the repair on {$order->order_number}.");
            }

            return response()->json(['success' => true, 'message' => $byShop ? 'Saved — the customer keeps the refund request.' : 'You kept your refund request. The shop will decide on it.', 'data' => $claim->fresh()->toClientArray($byShop)]);
        }

        // A due date already in the past by now moves to today.
        $dueDate = $claim->offered_due_date && $claim->offered_due_date->gte(today()) ? $claim->offered_due_date->toDateString() : today()->toDateString();
        $claim->update(['type' => 'repair']);
        $repair = $this->startRepair($store, $order, $claim, $dueDate, $claim->offered_staff_id, $request->user()->id);

        if ($byShop) {
            $this->notifyCustomer($claim, 'claim_accepted', 'Repair Accepted',
                "Your free repair for {$order->order_number} has started. Track it as {$repair->order_number}.");
        } else {
            $this->notifyShop($request, $order, $claim, false, 'claim_offer_accepted', 'Repair Offer Accepted',
                ($order->customer?->name ?? 'The customer')." accepted the free repair on {$order->order_number} — {$repair->order_number} was added to production.");
        }

        return response()->json([
            'success' => true,
            'message' => $byShop ? "Repair started — {$repair->order_number} was added to production." : "Repair accepted. Track it as {$repair->order_number}.",
            'data' => $claim->fresh('repairJobOrder')->toClientArray($byShop),
        ]);
    }

    /**
     * Due date (required, not in the past) and optional staff from this shop and the order's branch.
     *
     * @return array{0: string, 1: ?int}
     */
    private function repairDetails(Request $request, Store $store, JobOrder $order): array
    {
        $validated = $request->validate([
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'assigned_staff_id' => ['nullable', 'integer'],
        ], [
            'due_date.required' => 'Set a due date for the repair.',
            'due_date.after_or_equal' => 'The due date can\'t be in the past.',
        ]);
        $staffId = $validated['assigned_staff_id'] ?? null;
        if ($staffId) {
            $profile = $store->staff()->where('user_id', $staffId)->first();
            if (! $profile) {
                abort(422, 'Pick a staff member from this shop.');
            }
            if ($profile->store_branch_id && $order->store_branch_id && (int) $profile->store_branch_id !== (int) $order->store_branch_id) {
                abort(422, 'That staff member works at another branch.');
            }
        }

        return [$validated['due_date'], $staffId ? (int) $staffId : null];
    }

    /** Creates the linked ₱0 warranty-repair order and moves the claim to "repairing". */
    private function startRepair(Store $store, JobOrder $order, OrderClaim $claim, string $dueDate, ?int $staffId, int $actorId): JobOrder
    {
        return DB::transaction(function () use ($store, $order, $claim, $dueDate, $staffId, $actorId) {
            $orderNumber = OrderNumber::next($store->id);
            $repair = JobOrder::create([
                'order_number' => $orderNumber,
                'tracking_code' => JobOrderController::generateTrackingCode($store, $orderNumber),
                'intake_channel' => $order->intake_channel,
                'fulfillment_type' => 'pickup',
                'store_id' => $store->id,
                'store_branch_id' => $order->store_branch_id,
                'customer_id' => $order->customer_id,
                'service_id' => $order->service_id,
                'measurement_id' => $order->measurement_id,
                'garment_category' => 'alteration_repair',
                'material_source' => 'customer_supplied',
                'quantity' => 1,
                'total_amount' => 0,
                'balance' => 0,
                'payment_status' => 'paid',
                'payment_policy' => 'none',
                'payment_policy_percent' => 0,
                'measurement_requirement' => 'none',
                'fitting_requirement' => 'optional',
                'status' => 'production',
                'due_date' => $dueDate,
                'assigned_staff_id' => $staffId,
                'assigned_to' => $staffId,
                'reference_images' => $claim->photos,
                'notes' => "Warranty repair for {$order->order_number} — ".(self::REASON_LABELS[$claim->reason] ?? $claim->reason).": {$claim->description}",
                'custom_order_data' => ['warranty_claim_id' => $claim->id, 'warranty_for_order' => $order->order_number],
            ]);
            $claim->update([
                'status' => 'repairing',
                'decided_by_id' => $claim->decided_by_id ?? $actorId,
                'decided_at' => $claim->decided_at ?? now(),
                'repair_job_order_id' => $repair->id,
            ]);

            return $repair;
        });
    }

    public function decline(Request $request, Store $store, OrderClaim $claim): JsonResponse
    {
        if ($denied = $this->claimDenied($request, $store, $claim)) {
            return $denied;
        }
        if ($claim->status !== 'submitted') {
            return response()->json(['success' => false, 'message' => $claim->status === 'repair_offered' ? 'Waiting for the customer to answer the repair offer.' : 'This claim has already been decided.'], 422);
        }
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], [
            'reason.required' => 'Tell the customer why the claim is declined.',
        ]);

        $claim->update([
            'status' => 'declined',
            'decline_reason' => $validated['reason'],
            'decided_by_id' => $request->user()->id,
            'decided_at' => now(),
            'resolved_at' => now(),
        ]);
        $this->notifyCustomer($claim, 'claim_declined', 'Claim Declined',
            "{$store->name} declined your claim on {$claim->jobOrder->order_number}: {$validated['reason']}");

        return response()->json(['success' => true, 'message' => 'Claim declined. The customer was notified.', 'data' => $claim->fresh()->toClientArray(true)]);
    }

    /** Records the refund once the garment is back. The money itself is paid outside SUTURA. */
    public function refund(Request $request, Store $store, OrderClaim $claim): JsonResponse
    {
        if ($denied = $this->claimDenied($request, $store, $claim)) {
            return $denied;
        }
        if ($claim->status !== 'awaiting_return') {
            return response()->json(['success' => false, 'message' => 'Only an accepted refund can be recorded.'], 422);
        }
        $order = $claim->jobOrder;
        $paid = OrderClaim::paidAmount($order);

        $validated = $request->validate([
            'garment_returned' => ['accepted'],
            'amount' => ['required', 'numeric', 'min:1', 'max:'.max(1, $paid)],
            'method' => ['required', Rule::in(OrderClaim::REFUND_METHODS)],
            'reference' => ['nullable', 'string', 'max:255'],
            'proof_path' => ['nullable', 'string', 'max:2048'],
        ], [
            'garment_returned.accepted' => 'The garment must be returned before refunding.',
            'amount.max' => 'The refund can\'t be more than what the customer paid (₱'.number_format($paid, 2).').',
        ]);

        $claim->update([
            'status' => 'refunded',
            'garment_returned_at' => now(),
            'refund_amount' => round((float) $validated['amount'], 2),
            'refund_method' => $validated['method'],
            'refund_reference' => $validated['reference'] ?? null,
            'refund_proof_path' => $validated['proof_path'] ?? null,
            'refunded_at' => now(),
            'resolved_at' => now(),
        ]);
        $this->notifyCustomer($claim, 'claim_refunded', 'Refund Sent',
            "{$store->name} refunded ₱".number_format((float) $validated['amount'], 2)." for {$order->order_number} via ".($validated['method'] === 'gcash' ? 'GCash' : 'cash').'.');

        return response()->json(['success' => true, 'message' => 'Refund recorded.', 'data' => $claim->fresh()->toClientArray(true)]);
    }

    /** Called when a warranty-repair order is completed: the claim is resolved and a new window opens. */
    public static function resolveRepair(JobOrder $repair): void
    {
        OrderClaim::where('repair_job_order_id', $repair->id)->where('status', 'repairing')
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    // ── Shared ──────────────────────────────────────────────────────────────

    private function file(Request $request, JobOrder $order, bool $byShop): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(OrderClaim::TYPES)],
            'reason' => ['required', Rule::in(OrderClaim::REASONS)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'photos' => ['required', 'array', 'min:1', 'max:5'],
            'photos.*' => ['required', 'string', 'max:2048'],
        ], [
            'photos.required' => 'Add at least one photo of the problem.',
            'photos.min' => 'Add at least one photo of the problem.',
            'photos.max' => 'Up to 5 photos.',
            'description.min' => 'Describe the problem in a few words (at least 10 characters).',
        ]);

        $claim = DB::transaction(function () use ($order, $validated, $request) {
            // Row lock so two quick submits can't both pass the one-claim rule.
            $locked = JobOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();
            $check = OrderClaim::eligibility($locked);
            if (! $check['can_file']) {
                abort(422, $check['reason']);
            }

            return OrderClaim::create([
                'store_id' => $locked->store_id,
                'job_order_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'filed_by_id' => $request->user()->id,
                'type' => $validated['type'],
                'reason' => $validated['reason'],
                'description' => $validated['description'],
                'photos' => array_values($validated['photos']),
                'status' => 'submitted',
            ]);
        });

        $this->notifyShop($request, $order, $claim, $byShop);

        return response()->json([
            'success' => true,
            'message' => $byShop ? 'Claim filed.' : 'Problem reported. The shop will review it and get back to you.',
            'data' => $claim->toClientArray($byShop),
        ], 201);
    }

    private function payload(JobOrder $order, bool $forShop): array
    {
        $claims = OrderClaim::where('job_order_id', $order->id)->with(['repairJobOrder', 'filedBy:id,name', 'decidedBy:id,name'])->latest()->get();

        return array_merge(OrderClaim::eligibility($order), [
            'window_days' => OrderClaim::WINDOW_DAYS,
            'paid_amount' => OrderClaim::paidAmount($order),
            'claims' => $claims->map(fn (OrderClaim $c) => $c->toClientArray($forShop))->values(),
        ]);
    }

    private function orderDenied(Request $request, Store $store, JobOrder $order): ?JsonResponse
    {
        if ($order->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }
        $user = $request->user();
        $branchId = $user->hasRole('store_owner') ? null : ($user->staffProfile->store_branch_id ?? null);
        if ($branchId && $order->store_branch_id && (int) $branchId !== (int) $order->store_branch_id) {
            return response()->json(['success' => false, 'message' => 'This job order belongs to a different branch.'], 403);
        }

        return null;
    }

    private function claimDenied(Request $request, Store $store, OrderClaim $claim): ?JsonResponse
    {
        if ($claim->store_id !== $store->id || ! $claim->jobOrder) {
            return response()->json(['success' => false, 'message' => 'Claim not found'], 404);
        }

        return $this->orderDenied($request, $store, $claim->jobOrder);
    }

    /** Owner + that branch's managers (never the person who filed it). */
    private function notifyShop(Request $request, JobOrder $order, OrderClaim $claim, bool $byShop, string $type = 'claim_filed', ?string $title = null, ?string $message = null): void
    {
        $store = $order->store;
        $recipients = collect([$store?->owner]);
        if ($order->store_branch_id) {
            $recipients = $recipients->merge(User::whereHas('staffProfile', fn ($q) => $q->where('store_id', $order->store_id)
                ->where('store_branch_id', $order->store_branch_id)->where('is_branch_manager', true))->get());
        }
        $what = $claim->type === 'refund' ? 'a refund' : 'a repair';
        $who = $byShop ? 'Filed at the counter' : ($order->customer?->name ?? 'A customer');

        foreach ($recipients->filter()->unique('id')->where('id', '!=', $request->user()->id) as $user) {
            $user->notify(new StoreActivityNotification(
                $type,
                $title ?? 'Problem Reported',
                $message ?? "{$who} asked for {$what} on {$order->order_number}: ".(self::REASON_LABELS[$claim->reason] ?? $claim->reason).'.',
                '/dashboard/jobs/'.$order->id,
                ['job_order_id' => $order->id, 'order_number' => $order->order_number, 'claim_id' => $claim->id, 'store_branch_id' => $order->store_branch_id]
            ));
        }
    }

    private function notifyCustomer(OrderClaim $claim, string $type, string $title, string $message): void
    {
        $order = $claim->jobOrder;
        $order?->customer?->notify(new StoreActivityNotification(
            $type, $title, $message, '/account/orders/'.$order->id,
            ['job_order_id' => $order->id, 'order_number' => $order->order_number, 'claim_id' => $claim->id]
        ));
    }
}
