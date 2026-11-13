<?php

namespace App\Notifications;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired once by app:expire-subscriptions when a store's subscription lapses
 * and the store is auto-hidden as a result — matches
 * OverdueJobsNotification's "database only, owner-facing" precedent.
 */
class SubscriptionExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Store $store;

    public function __construct(Store $store, public bool $wasTrial = false)
    {
        $this->store = $store;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_expired',
            'title' => $this->wasTrial ? 'Free Trial Ended' : 'Subscription Expired',
            'message' => $this->wasTrial
                ? "{$this->store->name}'s free trial has ended, so your shop is paused and hidden from customers. Your records are kept. Choose a plan to open it again."
                : "{$this->store->name}'s subscription has expired and your store is now hidden from customers. Renew to restore visibility.",
            'action_url' => '/dashboard/billing',
            'store_id' => $this->store->id,
        ];
    }
}
