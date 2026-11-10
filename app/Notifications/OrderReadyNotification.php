<?php

namespace App\Notifications;

use App\Models\JobOrder;
use App\Models\User;
use Illuminate\Bus\Queueable;
use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\SmsTemplates;
use App\Support\OrderMailBox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public JobOrder $jobOrder;

    // Which staff/owner marked this order ready — lets the customer-facing
    // notification row show the actual person's avatar instead of always
    // falling back to the store logo.
    public ?User $actor;

    public function __construct(JobOrder $jobOrder, ?User $actor = null)
    {
        $this->jobOrder = $jobOrder;
        $this->actor = $actor;
    }

    /**
     * Delivery channels — database + mail, unless this is a synthetic walk-in
     * placeholder address (no real customer inbox to deliver to).
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->hasMailableEmail()) {
            $channels[] = 'mail';
        }
        $channels[] = SmsChannel::class;

        return $channels;
    }

    public function toSms(object $notifiable): ?array
    {
        $text = SmsTemplates::order($this->jobOrder, 'ready_for_pickup');

        return $text ? $text + ['store_id' => $this->jobOrder->store_id, 'related_type' => 'job_order', 'related_id' => $this->jobOrder->id] : null;
    }

    /**
     * Mail representation.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $store = $this->jobOrder->store;
        $storeUrl = $store?->slug ? url(env('FRONTEND_URL', 'http://localhost:3000').'/store/'.$store->slug) : null;

        $mail = (new MailMessage)
            ->subject('Ready for Pickup — '.$this->jobOrder->order_number.' · '.($store?->name ?? 'SUTURA'))
            ->greeting('Hello '.$notifiable->name.',')
            ->line(OrderMailBox::make($this->jobOrder->order_number, $this->jobOrder->tracking_code, $store?->name))
            ->line('Great news! Your order ('.$this->jobOrder->order_number.') from '.($store?->name ?? 'the store').' is now ready.')
            ->line('Please visit the store to fit your garment. If everything is perfect, you can pay your remaining balance of ₱'.number_format($this->jobOrder->balance, 2).' and take it home.')
            ->line('If any final adjustments are needed, our tailors will handle them on-site.');

        if ($storeUrl) {
            $mail->action('Visit '.$store->name, $storeUrl);
        }

        return $mail->line('Thank you for trusting us with your custom tailoring!');
    }

    /**
     * Database payload — used by the NotificationBell on the frontend.
     */
    public function toArray(object $notifiable): array
    {
        $store = $this->jobOrder->store;

        return [
            'type' => 'order_ready',
            'title' => 'Order Ready for Pickup',
            'message' => 'Order '.$this->jobOrder->order_number.' is ready for pickup.',
            // Customer-facing (this notifiable is the customer, not the
            // shop owner) — must land on their own order tracker, not the
            // owner-only dashboard route.
            'action_url' => '/account/orders/'.$this->jobOrder->id,
            'job_order_id' => $this->jobOrder->id,
            'order_number' => $this->jobOrder->order_number,
            'customer_name' => $this->jobOrder->customer?->name,
            'store' => $store ? [
                'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'logo_path' => $store->logo_path,
            ] : null,
            'actor' => $this->actor ? [
                'id' => $this->actor->id, 'name' => $this->actor->name, 'profile_picture' => $this->actor->profile_picture,
            ] : null,
        ];
    }
}
