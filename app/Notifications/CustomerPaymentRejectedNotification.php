<?php

namespace App\Notifications;

use App\Models\JobOrder;
use App\Models\Payment;
use App\Support\OrderMailBox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * PaymentRejectedNotification only ever reaches the store owner (and only
 * when a branch_manager did the rejecting) — the customer whose own payment
 * got rejected had no notification at all, in-app or email, and no way to
 * learn why short of asking the store directly. Separate class, not a reuse
 * of PaymentRejectedNotification, because the audience/tone/action_url all
 * differ: that one is an owner-oversight alert, this is a customer-facing
 * "please resubmit" message that surfaces the staff-entered reason.
 */
class CustomerPaymentRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public JobOrder $jobOrder;

    public Payment $payment;

    public string $reason;

    public function __construct(JobOrder $jobOrder, Payment $payment, string $reason)
    {
        $this->jobOrder = $jobOrder;
        $this->payment = $payment;
        $this->reason = $reason;
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->hasMailableEmail()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $store = $this->jobOrder->store;
        $storeUrl = $store?->slug ? url(env('FRONTEND_URL', 'http://localhost:3000').'/store/'.$store->slug) : null;

        $mail = (new MailMessage)
            ->subject('Payment Not Accepted — '.$this->jobOrder->order_number.' · '.($store?->name ?? 'SUTURA'))
            ->greeting('Hello '.$notifiable->name.',')
            ->line(OrderMailBox::make($this->jobOrder->order_number, $this->jobOrder->tracking_code, $store?->name))
            ->line('Your payment of ₱'.number_format((float) $this->payment->amount, 2).' for order '.$this->jobOrder->order_number.' could not be accepted.')
            ->line('Reason: '.$this->reason)
            ->line('Please get in touch with the store or submit a new payment to continue.');

        if ($storeUrl) {
            $mail->action('View Store', $storeUrl);
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        $store = $this->jobOrder->store;

        return [
            'type' => 'customer_payment_rejected',
            'title' => 'Payment Not Accepted',
            'message' => 'Your ₱'.number_format((float) $this->payment->amount, 2).' payment for order '.$this->jobOrder->order_number.' was not accepted. Reason: '.$this->reason,
            'action_url' => '/account/orders/'.$this->jobOrder->id,
            'job_order_id' => $this->jobOrder->id,
            'order_number' => $this->jobOrder->order_number,
            'payment_id' => $this->payment->id,
            'amount' => (float) $this->payment->amount,
            'reason' => $this->reason,
            'store' => $store ? [
                'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'logo_path' => $store->logo_path,
            ] : null,
        ];
    }
}
