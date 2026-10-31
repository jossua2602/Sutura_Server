<?php

namespace App\Notifications;

use App\Models\JobOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewJobOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public JobOrder $jobOrder;

    public function __construct(JobOrder $jobOrder)
    {
        $this->jobOrder = $jobOrder;
    }

    /**
     * Delivery channels — database only (in-app notification).
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Database payload.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_job_order',
            'title' => 'New Job Order Created',
            'message' => 'Job order '.$this->jobOrder->order_number.' was created for '.($this->jobOrder->customer?->name ?? 'a customer')
                .($this->jobOrder->branch ? ' at '.$this->jobOrder->branch->name : '').'.',
            'action_url' => '/dashboard/jobs/'.$this->jobOrder->id,
            'job_order_id' => $this->jobOrder->id,
            'order_number' => $this->jobOrder->order_number,
            'customer_name' => $this->jobOrder->customer?->name,
            // The branch the order was placed at (also stored in notifications.branch_id).
            'branch_id' => $this->jobOrder->store_branch_id,
            'branch_name' => $this->jobOrder->branch?->name,
        ];
    }
}
