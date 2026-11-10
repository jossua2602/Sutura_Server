<?php

namespace App\Notifications;

use App\Models\CatalogOrder;
use App\Support\OrderMailBox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Same gap as AppointmentPaymentStatusNotification, for the walk-in/RTW
 * Catalog Order payment path (CatalogOrderController::verifyPayment) —
 * this one has a real total_amount to reference.
 */
class CatalogOrderPaymentStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public CatalogOrder $order;

    public string $status; // 'paid' or 'rejected'

    public function __construct(CatalogOrder $order, string $status)
    {
        $this->order = $order;
        $this->status = $status;
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
        $accepted = $this->status === 'paid';
        $amount = number_format((float) $this->order->total_amount, 2);
        // The shop's own order number (ORD-0014), not the internal row id.
        $number = $this->order->order_number ?: '#'.$this->order->id;
        $storeName = $this->order->store?->name;
        $mail = (new MailMessage)
            ->subject(($accepted ? 'Payment Confirmed' : 'Payment Not Accepted').' — '.$number.' · '.($storeName ?? 'SUTURA'))
            ->greeting('Hello '.$notifiable->name.',')
            ->line(OrderMailBox::make($number, null, $storeName));

        if ($accepted) {
            $mail->line('Your payment of ₱'.$amount.' for order '.$number.' has been confirmed. Thank you!');
        } else {
            $mail->line('Your payment of ₱'.$amount.' for order '.$number.' could not be accepted.')
                ->line('Please get in touch with the store or resubmit your payment.');
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        $accepted = $this->status === 'paid';
        $store = $this->order->store;

        return [
            'type' => 'catalog_order_payment_'.$this->status,
            'title' => $accepted ? 'Payment Confirmed' : 'Payment Not Accepted',
            'message' => $accepted
                ? 'Your payment for order #'.$this->order->id.' has been confirmed.'
                : 'Your payment for order #'.$this->order->id.' was not accepted.',
            // No dedicated customer-facing detail page exists for a walk-in
            // catalog order (only JobOrder has one, via /account/orders) —
            // the list is the closest real destination.
            'action_url' => '/account/orders',
            'catalog_order_id' => $this->order->id,
            'store' => $store ? [
                'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'logo_path' => $store->logo_path,
            ] : null,
        ];
    }
}
