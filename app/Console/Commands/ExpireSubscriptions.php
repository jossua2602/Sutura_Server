<?php

namespace App\Console\Commands;

use App\Models\StoreSubscription;
use App\Models\SubscriptionEvent;
use App\Notifications\SubscriptionExpiredNotification;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'app:expire-subscriptions';

    protected $description = 'Marks past-due subscriptions as expired and hides the store from customers until renewed — matches REQUIREMENTS.md Phase 5: "Expired subscriptions automatically downgrade store visibility to Hidden until renewed."';

    public function handle(): int
    {
        $expired = 0;

        StoreSubscription::whereIn('status', ['active', 'trial'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->with('store')
            ->each(function (StoreSubscription $subscription) use (&$expired) {
                // A queued downgrade (free, or already paid) takes over instead of expiring.
                if (\App\Support\SubscriptionSwitcher::applyScheduled($subscription)) {
                    return;
                }
                $wasTrial = $subscription->status === 'trial';
                $subscription->update(['status' => 'expired']);

                SubscriptionEvent::create([
                    'store_id' => $subscription->store_id,
                    'store_subscription_id' => $subscription->id,
                    'event_type' => 'expired',
                    'plan_id' => $subscription->plan_id,
                    'previous_plan_id' => null,
                    'billing_cycle' => null,
                    'triggered_by' => null, // system-triggered, not an owner action
                    'occurred_at' => now(),
                ]);

                $store = $subscription->store;
                if ($store) {
                    // A lapsed Premium subscription loses its featured
                    // placement immediately — mirrors the same
                    // auto-sync SubscriptionController::subscribe() does
                    // on the way in, so is_featured never sits stale once
                    // the plan that earned it is gone.
                    $updates = ['is_featured' => false];
                    if (! $store->is_hidden) {
                        $updates['is_hidden'] = true;
                        if ($store->owner) {
                            $store->owner->notify(new SubscriptionExpiredNotification($store, $wasTrial));
                        }
                    }
                    $store->update($updates);
                }

                $expired++;
            });

        $this->info("Expired {$expired} subscription(s) and hid the corresponding store(s).");

        return self::SUCCESS;
    }
}
