<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionUpgradeRequest;
use App\Support\SubscriptionSwitcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shop owner's side of a paid plan change: send the fee by GCash to the
 * platform, then submit the receipt. The store stays on its current plan
 * until an admin approves (Admin\SubscriptionUpgradeRequestController).
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
                ->latest()
                ->limit(10)
                ->get(),
        ]);
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

        if ($error = SubscriptionSwitcher::misfit($store, $plan)) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        // A paid downgrade is already queued: that period is paid for.
        if (SubscriptionSwitcher::current($store)?->scheduled_payment_id) {
            return response()->json([
                'success' => false,
                'message' => 'You already paid for a plan change that starts when your current plan ends. Contact SUTURA support to change it.',
            ], 422);
        }

        $kind = SubscriptionSwitcher::kind($store, $plan);
        // Amount due always comes from the plan, never from the form.
        $price = $validated['billing_cycle'] === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        $referenceKey = SubscriptionUpgradeRequest::referenceKey($validated['payment_reference'] ?? null);
        $receiptPath = $request->file('payment_receipt')->store('subscription-upgrades/receipts', self::privateDisk());

        try {
            $payment = DB::transaction(function () use ($request, $store, $plan, $validated, $kind, $price, $referenceKey, $receiptPath) {
                // Lock the store's rows so two quick submits can't both pass the checks.
                $open = SubscriptionUpgradeRequest::where('store_id', $store->id)->lockForUpdate()->get();
                if ($open->contains(fn ($p) => $p->status === 'pending' && $p->type !== 'new_shop')) {
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
