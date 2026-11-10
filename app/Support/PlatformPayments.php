<?php

namespace App\Support;

use App\Models\Store;
use App\Models\SubscriptionUpgradeRequest;
use App\Notifications\StoreActivityNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approving / rejecting a GCash payment (Admin → Payments). One place, so the
 * rules hold everywhere: only pending → paid or pending → rejected, never
 * twice (the row is locked), every decision is audit-logged and timelined,
 * and the owner is told.
 *
 * What "paid" does depends on the payment type:
 *  - upgrade  → the new plan starts now
 *  - renewal  → the same plan is extended from its current end date
 *  - downgrade→ the cheaper plan is queued for when the current period ends
 *  - new_shop → decided with the shop application, not here
 */
class PlatformPayments
{
    public static function approve(Request $request, SubscriptionUpgradeRequest $payment, ?string $checklistNote = null): string
    {
        $adminId = $request->user()->id;

        return DB::transaction(function () use ($request, $payment, $adminId, $checklistNote) {
            $payment = SubscriptionUpgradeRequest::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'pending') {
                throw ValidationException::withMessages(['payment' => 'This payment has already been reviewed.']);
            }
            if ($payment->type === 'new_shop') {
                throw ValidationException::withMessages(['payment' => 'A new shop’s payment is decided with its application.']);
            }

            $store = $payment->store;
            $plan = $payment->plan;
            // Usage may have changed since the payment was sent.
            if ($error = SubscriptionSwitcher::misfit($store, $plan)) {
                throw ValidationException::withMessages(['payment' => $error]);
            }

            $current = SubscriptionSwitcher::current($store);
            $kind = SubscriptionSwitcher::kind($store, $plan);
            $days = SubscriptionSwitcher::days($payment->billing_cycle);

            if ($kind === 'downgrade' && $current) {
                // Keeps the current plan until its end, then the paid cheaper period.
                $start = $current->ends_at ?? now();
                $end = $start->copy()->addDays($days);
                $payment->fill(['billing_period_start' => $start, 'billing_period_end' => $end]);
                SubscriptionSwitcher::schedule($current, $plan, $payment->billing_cycle, $payment->id);
                $message = "{$store->name} switches to {$plan->name} on {$start->timezone('Asia/Manila')->format('M j, Y')}.";
                $ownerMessage = "Your payment was confirmed. {$store->name} stays on {$current->plan->name} until {$start->timezone('Asia/Manila')->format('M j, Y')}, then moves to {$plan->name}.";
            } else {
                $subscription = SubscriptionSwitcher::apply($store, $plan, $payment->billing_cycle, $adminId);
                $payment->fill(['billing_period_start' => $subscription->ends_at->copy()->subDays($days), 'billing_period_end' => $subscription->ends_at]);
                $until = $subscription->ends_at->timezone('Asia/Manila')->format('M j, Y');
                $message = $kind === 'renewal' ? "{$store->name}’s {$plan->name} plan now runs until {$until}." : "{$store->name} is now on {$plan->name} until {$until}.";
                $ownerMessage = $kind === 'renewal'
                    ? "Your payment was confirmed. {$store->name}’s {$plan->name} plan now runs until {$until}."
                    : "Your payment was confirmed. {$store->name} is now on the {$plan->name} plan until {$until}.";
            }

            $payment->fill([
                'type' => $kind === 'downgrade' && ! $current ? 'upgrade' : $kind,
                'status' => 'approved',
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ])->save();
            $payment->log('approved', $adminId, $checklistNote);

            AdminAudit::record($request, 'platform_payment_approved', Store::class, $store->id, $store->name.' → '.$plan->name.' (₱'.number_format((float) $payment->quoted_price, 2).')', $store->id);

            $payment->requester?->notify(new StoreActivityNotification('plan_upgrade_approved', 'Payment Confirmed', $ownerMessage, '/dashboard/billing'));

            return $message;
        });
    }

    public static function reject(Request $request, SubscriptionUpgradeRequest $payment, string $reason): void
    {
        $adminId = $request->user()->id;

        DB::transaction(function () use ($request, $payment, $reason, $adminId) {
            $payment = SubscriptionUpgradeRequest::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'pending') {
                throw ValidationException::withMessages(['payment' => 'This payment has already been reviewed.']);
            }
            if ($payment->type === 'new_shop') {
                throw ValidationException::withMessages(['payment' => 'A new shop’s payment is decided with its application.']);
            }

            $payment->update(['status' => 'rejected', 'reviewed_by' => $adminId, 'reviewed_at' => now(), 'rejection_reason' => $reason]);
            $payment->log('rejected', $adminId, $reason);

            $store = $payment->store;
            AdminAudit::record($request, 'platform_payment_rejected', Store::class, $store->id, $store->name, $store->id, $reason);

            $payment->requester?->notify(new StoreActivityNotification(
                'plan_upgrade_rejected',
                'Payment Not Accepted',
                "We couldn't confirm your payment for the {$payment->plan->name} plan: {$reason} You can send it again from Billing.",
                '/dashboard/billing'
            ));
        });
    }

    /** The application's own payment follows the application decision. */
    public static function decideWithApplication(int $storeApplicationId, string $status, ?int $adminId, ?string $reason = null, ?\DateTimeInterface $periodStart = null, ?\DateTimeInterface $periodEnd = null): void
    {
        $payment = SubscriptionUpgradeRequest::where('store_application_id', $storeApplicationId)->where('status', 'pending')->first();
        if (! $payment) {
            return;
        }
        $payment->update([
            'status' => $status,
            'reviewed_by' => $adminId,
            'reviewed_at' => now(),
            'rejection_reason' => $status === 'rejected' ? 'Shop application rejected: '.$reason : null,
            'billing_period_start' => $periodStart,
            'billing_period_end' => $periodEnd,
        ]);
        $payment->log($status, $adminId, $status === 'approved' ? 'Decided with the shop application.' : 'Shop application rejected.');
    }
}
