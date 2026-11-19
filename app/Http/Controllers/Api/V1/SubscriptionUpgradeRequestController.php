<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionUpgradeRequest;
use App\Support\OnlinePlanPayments;
use App\Support\SubscriptionSwitcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shop owner's side of a paid plan change, two ways:
 *  - Pay with GCash (payOnline): a GCash checkout; the plan changes as soon as GCash confirms it.
 *  - Upload a GCash receipt (store): send the fee to SUTURA's GCash and attach the screenshot;
 *    the store stays on its current plan until an admin approves it.
 */
class SubscriptionUpgradeRequestController extends Controller
{
    private static function privateDisk(): string
    {
        return config('filesystems.private_disk', 'local');
    }

    private function ownsStore(Request $request, Store $store): bool
    {
        return $request->user()->stores()->where('id', $store->id)->exists();
    }

    public function index(Request $request, Store $store): JsonResponse
    {
        if (! $this->ownsStore($request, $store)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => SubscriptionUpgradeRequest::with('plan:id,name')
                ->where('store_id', $store->id)
                ->inLedger()
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }

    /** Version of the downgrade terms shown on Billing (bump when their wording changes). */
    public const DOWNGRADE_TERMS_VERSION = '2026-11-20-2';

    /** A downgrade can't be paid for until the owner agrees to its terms. */
    private static function downgradeTermsMissing(Request $request, string $kind): ?JsonResponse
    {
        if ($kind !== 'downgrade' || $request->boolean('accept_terms')) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Please read and accept the downgrade terms before paying.',
            'errors' => ['accept_terms' => ['Accept the downgrade terms to continue.']],
        ], 422);
    }

    /** Records, on the payment's history, that the owner accepted the downgrade terms (and what they said). */
    private static function logTermsAccepted(SubscriptionUpgradeRequest $payment, Store $store, SubscriptionPlan $plan, string $kind, int $userId): void
    {
        if ($kind !== 'downgrade') {
            return;
        }
        $current = SubscriptionSwitcher::current($store);
        $on = $current?->ends_at?->timezone('Asia/Manila')->format('M j, Y');
        $payment->log('terms_accepted', $userId, 'Accepted the downgrade terms (v'.self::DOWNGRADE_TERMS_VERSION.'): stays on '
            .($current?->plan?->name ?? 'the current plan').($on ? " until {$on}" : '').", then moves to {$plan->name}.");
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        if (! $this->ownsStore($request, $store)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            // What the owner sees in their GCash receipt.
            // Optional: the receipt screenshot is the proof; a typed ref no. just speeds up the check.
            'payment_reference' => ['nullable', 'string', 'min:6', 'max:50', 'regex:/^[A-Za-z0-9\s-]+$/'],
            'sender_name' => ['required', 'string', 'max:100'],
            'sender_number' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'amount_sent' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'paid_at' => ['required', 'date'],
            'payment_receipt' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            // A downgrade needs the owner's agreement to its terms (Billing shows them first).
            'accept_terms' => ['nullable', 'boolean'],
        ], [
            'payment_reference.regex' => 'The GCash reference number can only have letters, numbers, spaces and dashes.',
            'sender_number.regex' => 'Enter the GCash number you paid from: 11 digits starting with 09.',
        ]);

        $plan = SubscriptionPlan::where('is_active', true)->findOrFail($validated['plan_id']);

        if (! SubscriptionSwitcher::needsPayment($store, $plan, $validated['billing_cycle'])) {
            return response()->json([
                'success' => false,
                'message' => 'This plan is free — switch to it directly from Billing.',
            ], 422);
        }

        // Over the new plan's limits is fine: the extra is locked on the switch, never refused (PlanLimits).

        // A paid downgrade is already queued: that period is paid for.
        if (SubscriptionSwitcher::current($store)?->scheduled_payment_id) {
            return response()->json([
                'success' => false,
                'message' => 'You already paid for a plan change that starts when your current plan ends. Contact SUTURA support to change it.',
            ], 422);
        }

        $kind = SubscriptionSwitcher::kind($store, $plan);
        if ($refused = self::downgradeTermsMissing($request, $kind)) {
            return $refused;
        }
        // Amount due always comes from the plan, never from the form.
        $price = $validated['billing_cycle'] === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        $referenceKey = SubscriptionUpgradeRequest::referenceKey($validated['payment_reference'] ?? null);
        $receiptPath = $request->file('payment_receipt')->store('subscription-upgrades/receipts', self::privateDisk());

        try {
            $payment = DB::transaction(function () use ($request, $store, $plan, $validated, $kind, $price, $referenceKey, $receiptPath) {
                // Lock the store's rows so two quick submits can't both pass the checks.
                $open = SubscriptionUpgradeRequest::where('store_id', $store->id)->lockForUpdate()->get();
                if ($open->contains(fn ($p) => self::waitingForReview($p))) {
                    throw ValidationException::withMessages(['payment' => 'You already have a payment waiting for review. Wait for the SUTURA team to respond before sending another.']);
                }
                // One GCash transaction pays for one thing. A rejected one may be sent again.
                if (SubscriptionUpgradeRequest::referenceInUse($referenceKey)) {
                    throw ValidationException::withMessages(['payment_reference' => 'This GCash reference number was already sent for another payment. Check the number in your GCash receipt.']);
                }
                $rejected = $open->where('status', 'rejected')->sortByDesc('id')->first();
                $resubmits = $rejected && ! SubscriptionUpgradeRequest::where('resubmission_of_id', $rejected->id)->exists() ? $rejected : null;

                $payment = SubscriptionUpgradeRequest::create([
                    'store_id' => $store->id,
                    'type' => $kind,
                    'requested_by' => $request->user()->id,
                    'plan_id' => $plan->id,
                    'billing_cycle' => $validated['billing_cycle'],
                    'quoted_price' => $price,
                    'amount_sent' => $validated['amount_sent'],
                    'payment_method' => 'gcash',
                    'payment_reference' => isset($validated['payment_reference']) ? trim($validated['payment_reference']) : null,
                    'reference_key' => $referenceKey,
                    'sender_name' => trim($validated['sender_name']),
                    'sender_number' => $validated['sender_number'],
                    // The form sends UTC ("…Z"); the database keeps app-time (Asia/Manila),
                    // so convert first or it's saved 8 hours off.
                    'paid_at_reported' => \Illuminate\Support\Carbon::parse($validated['paid_at'])->setTimezone(config('app.timezone')),
                    'payment_receipt_path' => $receiptPath,
                    'resubmission_of_id' => $resubmits?->id,
                ]);
                $payment->log($resubmits ? 'resubmitted' : 'submitted', $request->user()->id, $resubmits ? "Sent again after payment #{$resubmits->id} was rejected." : null);
                self::logTermsAccepted($payment, $store, $plan, $kind, $request->user()->id);

                return $payment;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::privateDisk())->delete($receiptPath);
            throw $e;
        }

        $current = SubscriptionSwitcher::current($store);
        $message = match ($kind) {
            'downgrade' => 'Payment sent for review. Once confirmed, your shop moves to '.$plan->name.' when your current plan ends'
                .($current?->ends_at ? ' on '.$current->ends_at->timezone('Asia/Manila')->format('M j, Y') : '').'.',
            'renewal' => 'Payment sent for review. Your plan is extended once the SUTURA team confirms it.',
            default => 'Payment sent for review. Your plan changes once the SUTURA team confirms it.',
        };

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $payment->refresh()->load('plan:id,name'),
        ], 201);
    }

    /**
     * "Pay with GCash": opens a GCash checkout for this plan. Same checks as a receipt
     * upload; the plan changes as soon as GCash confirms the payment (no admin review).
     * Returns the checkout URL; the browser goes there and comes back to Billing.
     */
    public function payOnline(Request $request, Store $store): JsonResponse
    {
        if (! $this->ownsStore($request, $store)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'accept_terms' => ['nullable', 'boolean'],
        ]);
        $plan = SubscriptionPlan::where('is_active', true)->findOrFail($validated['plan_id']);

        if (! SubscriptionSwitcher::needsPayment($store, $plan, $validated['billing_cycle'])) {
            return response()->json(['success' => false, 'message' => 'This plan is free — switch to it directly from Billing.'], 422);
        }
        // Over the new plan's limits is fine: the extra is locked on the switch, never refused (PlanLimits).
        if (SubscriptionSwitcher::current($store)?->scheduled_payment_id) {
            return response()->json(['success' => false, 'message' => 'You already paid for a plan change that starts when your current plan ends. Contact SUTURA support to change it.'], 422);
        }

        $kind = SubscriptionSwitcher::kind($store, $plan);
        if ($refused = self::downgradeTermsMissing($request, $kind)) {
            return $refused;
        }
        $price = $validated['billing_cycle'] === 'yearly' ? $plan->price_yearly : $plan->price_monthly;

        $payment = DB::transaction(function () use ($request, $store, $plan, $validated, $kind, $price) {
            $open = SubscriptionUpgradeRequest::where('store_id', $store->id)->lockForUpdate()->get();
            if ($open->contains(fn ($p) => self::waitingForReview($p))) {
                throw ValidationException::withMessages(['payment' => 'You already have a payment waiting for review. Wait for the SUTURA team to respond before sending another.']);
            }

            // The same plan + cycle still unpaid: continue that checkout. Any other unpaid one is dropped.
            $reuse = $open->first(fn ($p) => $p->status === 'pending' && $p->isOnline() && $p->gateway_status !== 'paid'
                && $p->plan_id === $plan->id && $p->billing_cycle === $validated['billing_cycle'] && abs((float) $p->quoted_price - (float) $price) < 0.01);
            foreach ($open as $p) {
                if ($p->status === 'pending' && $p->isOnline() && $p->gateway_status !== 'paid' && $p->id !== $reuse?->id) {
                    $p->update(['status' => 'cancelled']);
                    $p->log('cancelled', $request->user()->id, 'Replaced by a new GCash checkout.');
                }
            }

            if ($reuse) {
                // Continuing a downgrade checkout: the owner agreed again just now.
                if (! $reuse->logs()->where('action', 'terms_accepted')->exists()) {
                    self::logTermsAccepted($reuse, $store, $plan, $kind, $request->user()->id);
                }

                return $reuse;
            }
            $payment = SubscriptionUpgradeRequest::create([
                'store_id' => $store->id,
                'type' => $kind,
                'requested_by' => $request->user()->id,
                'plan_id' => $plan->id,
                'billing_cycle' => $validated['billing_cycle'],
                'quoted_price' => $price,
                'payment_method' => 'gcash',
                'channel' => 'online',
                'status' => 'pending',
            ]);
            $payment->log('submitted', $request->user()->id, 'Paying with GCash.');
            self::logTermsAccepted($payment, $store, $plan, $kind, $request->user()->id);

            return $payment;
        });

        $url = OnlinePlanPayments::checkout($payment, '/dashboard/billing?payment='.$payment->id);

        return response()->json(['success' => true, 'data' => ['payment_id' => $payment->id, 'checkout_url' => $url]]);
    }

    /** Back from the GCash page: ask GCash how it went (works even where the webhook can't reach). */
    public function checkOnline(Request $request, Store $store, SubscriptionUpgradeRequest $upgradeRequest): JsonResponse
    {
        if (! $this->ownsStore($request, $store) || $upgradeRequest->store_id !== $store->id || ! $upgradeRequest->isOnline()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }
        try {
            $payment = OnlinePlanPayments::sync($upgradeRequest);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'message' => 'We could not reach GCash to check your payment. Refresh the page in a moment.'], 503);
        }

        return response()->json(['success' => true, 'data' => [
            'id' => $payment->id,
            'paid' => $payment->gateway_status === 'paid',
            'gateway_status' => $payment->gateway_status,
            'status' => $payment->status,
            'plan' => $payment->plan?->name,
            'billing_period_end' => $payment->billing_period_end?->toISOString(),
        ]]);
    }

    /** A payment the SUTURA team still has to look at (not an unpaid GCash checkout). */
    private static function waitingForReview(SubscriptionUpgradeRequest $p): bool
    {
        return $p->status === 'pending' && $p->type !== 'new_shop' && (! $p->isOnline() || $p->gateway_status === 'paid');
    }

    /** Cancel a free downgrade that's waiting for the current period to end. */
    public function cancelScheduled(Request $request, Store $store): JsonResponse
    {
        if (! $this->ownsStore($request, $store)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }
        $current = SubscriptionSwitcher::current($store);
        if (! $current?->scheduled_plan_id) {
            return response()->json(['success' => false, 'message' => 'There is no plan change waiting.'], 422);
        }
        if ($current->scheduled_payment_id) {
            return response()->json(['success' => false, 'message' => 'This plan change is already paid for. Contact SUTURA support to change it.'], 422);
        }
        $current->update(['scheduled_plan_id' => null, 'scheduled_billing_cycle' => null]);

        return response()->json(['success' => true, 'message' => 'Plan change cancelled. You stay on '.$current->plan->name.'.']);
    }

    /** The owner viewing back the receipt they attached. */
    public function receipt(Request $request, Store $store, SubscriptionUpgradeRequest $upgradeRequest): Response
    {
        if (! $this->ownsStore($request, $store) || $upgradeRequest->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        if (! Storage::disk(self::privateDisk())->exists($upgradeRequest->payment_receipt_path)) {
            return response()->json(['success' => false, 'message' => 'Receipt not found.'], 404);
        }

        return Storage::disk(self::privateDisk())->response($upgradeRequest->payment_receipt_path, null, ['Cache-Control' => 'private, no-store']);
    }
}
