<?php

namespace App\Support;

use App\Models\Store;
use App\Models\StoreSubscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;

/**
 * Moving a store onto a plan — shared by the direct switch (free / cheaper
 * plans, admins) and by an admin approving a paid upgrade request, so both go
 * through the same limit checks and leave the same subscription history.
 */
class SubscriptionSwitcher
{
    /**
     * Why the store's current usage does not fit this plan, or null when it does.
     * A move to a smaller plan doesn't need it anymore — the extra is locked,
     * not refused (PlanLimits) — so paid plan changes skip it for downgrades.
     */
    public static function misfit(Store $store, SubscriptionPlan $plan): ?string
    {
        $currentStaffCount = $store->staff()->count();
        if ($plan->max_staff !== -1 && $currentStaffCount > $plan->max_staff) {
            return "This plan allows up to {$plan->max_staff} staff member".($plan->max_staff === 1 ? '' : 's').", but you currently have {$currentStaffCount}. Remove staff first, or choose a plan that fits your current team size.";
        }

        // Branch Shops only: the Main Shop never counts toward the plan
        // (SubscriptionPlan::branchLimit, same as StoreBranchController::store).
        // Counting it here refused every move to a "main shop only" plan.
        $currentBranchCount = $store->branches()->where('is_main', false)->count();
        $branchLimit = $plan->branchLimit();
        if ($branchLimit !== -1 && $currentBranchCount > $branchLimit) {
            return $branchLimit === 0
                ? "This plan is for the main shop only, but you have {$currentBranchCount} branch shop".($currentBranchCount === 1 ? '' : 's').'. Remove them first, or choose a plan that fits your branches.'
                : "This plan allows up to {$branchLimit} branch shop".($branchLimit === 1 ? '' : 's').", but you have {$currentBranchCount}. Remove the extra branch shops first, or choose a plan that fits your branches.";
        }


        return null;
    }

    /**
     * True when getting onto this plan means paying: every paid plan, a step
     * down included (a cheaper paid plan used to switch for free, and that
     * free switch also started a new paid period).
     */
    public static function needsPayment(Store $store, SubscriptionPlan $plan, string $cycle): bool
    {
        return (float) ($cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly) > 0;
    }

    /** The store's running subscription (active or trial, not run out), if any. */
    public static function current(Store $store): ?StoreSubscription
    {
        return StoreSubscription::with('plan')
            ->where('store_id', $store->id)
            ->whereIn('status', ['active', 'trial'])
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest()
            ->first();
    }

    /**
     * What paying for this plan means for the store: renewal (same plan),
     * upgrade (pricier, or no running plan) or downgrade (cheaper; starts when
     * the running period ends).
     */
    public static function kind(Store $store, SubscriptionPlan $plan): string
    {
        $current = self::current($store);
        if (! $current) {
            $last = StoreSubscription::where('store_id', $store->id)->latest()->first();

            return $last && $last->plan_id === $plan->id ? 'renewal' : 'upgrade';
        }
        if ($current->plan_id === $plan->id) {
            return 'renewal';
        }

        return (float) $plan->price_monthly < (float) $current->plan->price_monthly ? 'downgrade' : 'upgrade';
    }

    /** Days a billing cycle buys (same rule everywhere). */
    public static function days(string $cycle): int
    {
        return $cycle === 'yearly' ? 365 : 30;
    }

    /**
     * Queue a switch for when the running period ends (a downgrade). A paid
     * downgrade carries its approved payment; a free one has none and can be
     * cancelled by the owner until then.
     */
    public static function schedule(StoreSubscription $current, SubscriptionPlan $plan, string $cycle, ?int $paymentId = null): void
    {
        $current->update([
            'scheduled_plan_id' => $plan->id,
            'scheduled_billing_cycle' => $cycle === 'yearly' ? 'yearly' : 'monthly',
            'scheduled_payment_id' => $paymentId,
        ]);
    }

    /**
     * Runs a queued switch once its subscription's period is over. Free plan →
     * switch. Paid plan → switch only if its payment was approved (for the
     * period that payment bought); otherwise the plan simply runs out, as any
     * unpaid plan does. Returns true when the switch happened.
     */
    public static function applyScheduled(StoreSubscription $sub): bool
    {
        if (! $sub->scheduled_plan_id || ($sub->ends_at && $sub->ends_at->isFuture())) {
            return false;
        }
        $plan = $sub->scheduledPlan;
        $cycle = $sub->scheduled_billing_cycle ?? 'monthly';
        if (! $plan) {
            return false;
        }

        $payment = $sub->scheduledPayment;
        if (self::needsPayment($sub->store, $plan, $cycle) && $payment?->status !== 'approved') {
            return false;
        }

        self::apply($sub->store, $plan, $cycle, null, $payment?->billing_period_end);
        $payment?->log('scheduled_change_applied', null, "The shop switched to {$plan->name}.");

        return true;
    }

    /**
     * Puts the store on this plan now. A renewal of the plan it's already on
     * adds to the time left instead of restarting it; $endsAt overrides the end
     * (a scheduled downgrade's paid period).
     */
    public static function apply(Store $store, SubscriptionPlan $plan, string $cycle, ?int $triggeredBy, ?\DateTimeInterface $endsAt = null): StoreSubscription
    {
        // Read the previous subscription BEFORE cancelling it — needed to
        // classify the event below (created/renewed/upgraded/downgraded),
        // and the update() call two lines down overwrites its status to
        // 'cancelled' with no way to read the "was this the same plan or a
        // different one" fact afterward.
        $previousSubscription = StoreSubscription::where('store_id', $store->id)
            ->whereIn('status', ['active', 'trial'])
            ->latest()
            ->first();

        // Cancel previous active subscription if it exists
        StoreSubscription::where('store_id', $store->id)
            ->whereIn('status', ['active', 'trial'])
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'ends_at' => now(),
            ]);

        // Days still left on the running plan are kept, never thrown away:
        //  - renewing the same plan early adds the new period after them;
        //  - a free trial's days carry over whichever plan is paid for;
        //  - an upgrade (a pricier plan, e.g. Pro → Premium) carries them over
        //    day for day: Pro with 10 days left + Premium monthly = 40 days of
        //    Premium, starting now. (A downgrade never gets here early — it is
        //    scheduled for when the running period ends.)
        $stillRunning = $previousSubscription && $previousSubscription->ends_at && $previousSubscription->ends_at->isFuture();
        $previousPlan = $previousSubscription ? SubscriptionPlan::find($previousSubscription->plan_id) : null;
        $isUpgrade = $previousPlan && $previousPlan->id !== $plan->id
            && (float) $plan->price_monthly > (float) $previousPlan->price_monthly;
        $renewing = $stillRunning
            && ($previousSubscription->plan_id === $plan->id || $previousSubscription->status === 'trial' || $isUpgrade);
        $base = $renewing ? $previousSubscription->ends_at->copy() : now();
        // Whole days carried over from the old plan on an upgrade (shown to the owner and the admin).
        $carriedDays = $stillRunning && $isUpgrade && $previousSubscription->status !== 'trial'
            ? (int) ceil(now()->diffInSeconds($previousSubscription->ends_at, false) / 86400)
            : 0;

        $newSubscription = StoreSubscription::create([
            'store_id' => $store->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => $cycle === 'yearly' ? 'yearly' : 'monthly',
            'starts_at' => now(),
            'ends_at' => $endsAt ?? $base->addDays(self::days($cycle)),
        ]);

        // Mirrors app:expire-subscriptions' auto-hide-on-expiry — a store the
        // system hid for a lapsed subscription should come back the moment
        // the owner renews, not stay hidden until they separately notice and
        // flip the visibility toggle themselves.
        // ...unless an admin took the store down — renewing a plan must not
        // lift a moderation takedown.
        Store::where('id', $store->id)->whereNull('admin_hidden_at')->update(['is_hidden' => false]);

        // "Featured Store Visibility (Top Placement)" is a Premium-plan perk
        // — kept in sync automatically with whatever plan the store is on
        // right now, not a toggle the owner controls themselves. Every
        // subscribe/renew/upgrade/downgrade recomputes it here, so a
        // downgrade away from Premium loses featured placement immediately
        // instead of the column silently staying stale.
        Store::where('id', $store->id)->update([
            'is_featured' => in_array('Featured Store Visibility (Top Placement)', $plan->features ?? [], true),
        ]);

        // Objective 7's "subscription activity" reporting needs a real
        // event log, not just the latest StoreSubscription row (which only
        // ever shows the current state, never the history of how a store
        // got there).
        if (! $previousSubscription) {
            $eventType = 'created';
        } elseif ($previousSubscription->plan_id === $plan->id) {
            $eventType = 'renewed';
        } else {
            $eventType = ($previousPlan && $previousPlan->price_monthly < $plan->price_monthly) ? 'upgraded' : 'downgraded';
        }

        SubscriptionEvent::create([
            'store_id' => $store->id,
            'store_subscription_id' => $newSubscription->id,
            'event_type' => $eventType,
            'plan_id' => $plan->id,
            'previous_plan_id' => $previousSubscription?->plan_id,
            'billing_cycle' => $cycle,
            'triggered_by' => $triggeredBy,
            'occurred_at' => now(),
        ]);

        // Lock what the new plan has no room for (a downgrade), or unlock what now
        // fits again (an upgrade). Never deletes anything — see PlanLimits.
        PlanLimits::enforce($store, $plan);

        // Not a column: lets the caller say "+10 days carried over from Pro".
        $newSubscription->carried_days = $carriedDays;
        $newSubscription->carried_from_plan = $carriedDays > 0 ? $previousPlan?->name : null;

        return $newSubscription;
    }
}
