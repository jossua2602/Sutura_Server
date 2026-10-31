<?php

namespace App\Notifications\Channels;

use App\Models\JobOrder;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Laravel's database channel, plus the `branch_id` column: the branch of the
 * job order the notification is about. One place for every notification
 * class, so none of them can forget to tag its branch. Anything not about a
 * job order (subscription, reviews, branch verification for the owner, …)
 * stays store-wide (null).
 */
class BranchAwareDatabaseChannel extends DatabaseChannel
{
    protected function buildPayload($notifiable, Notification $notification)
    {
        $payload = parent::buildPayload($notifiable, $notification);
        $jobOrderId = $payload['data']['job_order_id'] ?? null;

        $payload['branch_id'] = $jobOrderId
            ? JobOrder::withTrashed()->whereKey($jobOrderId)->value('store_branch_id')
            : null;

        return $payload;
    }
}
