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

/**
 * Fires on every production-stage transition EXCEPT ready_for_pickup, which
 * keeps its own dedicated OrderReadyNotification (balance-due copy, pickup
 * CTA) so customers don't get two overlapping emails for that one stage.
 */
class JobStatusUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public JobOrder $jobOrder;

    public string $status; // 'design', 'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing', 'ready_for_fitting', 'final_adjustments', 'qc_ironing', 'completed', 'cancelled', 'rejected'

    // Which staff/owner made this change — lets the customer-facing
    // notification row show the actual person's avatar instead of always
    // falling back to the store logo.
    public ?User $actor;

    public function __construct(JobOrder $jobOrder, string $status, ?User $actor = null)
    {
        $this->jobOrder = $jobOrder;
        $this->status = $status;
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
        if (in_array($this->status, ['ready_for_fitting', 'approved', 'production', 'rejected'], true) && ! empty($notifiable->phone)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    public function toSms(object $notifiable): ?array
    {
        $text = SmsTemplates::order($this->jobOrder, $this->status);

        return $text ? $text + ['store_id' => $this->jobOrder->store_id, 'related_type' => 'job_order', 'related_id' => $this->jobOrder->id] : null;
    }

    private function titles(): array
    {
        return [
            'approved' => 'Order Approved',
            'production' => 'Order In Production',
            'quality_check' => 'Order In Progress: Quality Check',
            'adjustments' => 'Order In Progress: Adjustments',
            'design' => 'Order In Progress: Design',
            'pattern_making' => 'Order In Progress: Pattern Making',
            'mass_cutting_printing' => 'Order In Progress: Mass Cutting & Printing',
            'cutting' => 'Order In Progress: Cutting',
            'sewing' => 'Order In Progress: Sewing & Assembly',
            'ready_for_fitting' => 'Order Ready for Fitting',
            'final_adjustments' => 'Order In Progress: Final Adjustments',
            'qc_ironing' => 'Order In Progress: Quality Check & Ironing',
            'completed' => 'Order Completed',
            'cancelled' => 'Order Cancelled',
            'rejected' => 'Order Declined',
        ];
    }

    /** "Order ORD-0012: " + the shop's Ready-for-Fitting message (Booking Flow Setup). */
    private function fittingMessage(): string
    {
        return 'Order '.$this->jobOrder->order_number.': '.$this->jobOrder->fittingMessage();
    }

    private function messages(): array
    {
        return [
            'approved' => 'Your order ('.$this->jobOrder->order_number.') has been approved and moved to production.',
            'production' => 'Your order ('.$this->jobOrder->order_number.') is now in production.',
            'quality_check' => 'Your order ('.$this->jobOrder->order_number.') is receiving its quality check.',
            'adjustments' => 'Your order ('.$this->jobOrder->order_number.') is undergoing adjustments after your fitting.',
            'design' => 'Your order ('.$this->jobOrder->order_number.') is now in the design stage.',
            'pattern_making' => 'Your order ('.$this->jobOrder->order_number.') is now having its pattern drafted.',
            'mass_cutting_printing' => 'Your order ('.$this->jobOrder->order_number.') has entered mass cutting and printing.',
            'cutting' => 'Your order ('.$this->jobOrder->order_number.') has entered the cutting stage.',
            'sewing' => 'Your order ('.$this->jobOrder->order_number.') is now being sewn and assembled.',
            'ready_for_fitting' => $this->fittingMessage(),
            'final_adjustments' => 'Your order ('.$this->jobOrder->order_number.') is undergoing final adjustments after your fitting.',
            'qc_ironing' => 'Your order ('.$this->jobOrder->order_number.') is receiving its final quality check and ironing.',
            'completed' => 'Your order ('.$this->jobOrder->order_number.') is now complete. Thank you for trusting us with your custom tailoring!',
            'cancelled' => 'Your order ('.$this->jobOrder->order_number.') has been cancelled.',
            'rejected' => 'Your order ('.$this->jobOrder->order_number.') could not be accepted.'.($this->jobOrder->rejection_reason ? ' Reason: '.$this->jobOrder->rejection_reason : ' Please contact the shop for details.'),
        ];
    }

    /**
     * Mail representation — reuses the same title/message copy as the in-app
     * notification so both channels stay in sync automatically.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->titles()[$this->status] ?? 'Order Update';
        $message = $this->messages()[$this->status] ?? 'Your order status has been updated.';

        $store = $this->jobOrder->store;
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $trackingUrl = $this->jobOrder->tracking_code
            ? "{$frontendUrl}/track/{$this->jobOrder->tracking_code}"
            : "{$frontendUrl}/account/orders/{$this->jobOrder->id}";

        $mail = (new MailMessage)
            ->subject($title.' — '.$this->jobOrder->order_number.' · '.($store?->name ?? 'SUTURA'))
            ->greeting('Hello '.$notifiable->name.',')
            ->line(OrderMailBox::make($this->jobOrder->order_number, $this->jobOrder->tracking_code, $store?->name))
            ->line($message);

        if ($this->status === 'rejected') {
            if ($this->jobOrder->rejection_reason) {
                $mail->line('Reason / Notes: '.$this->jobOrder->rejection_reason);
            }
            $storeUrl = $store?->slug ? "{$frontendUrl}/store/{$store->slug}" : null;
            if ($storeUrl) {
                $mail->action('Contact '.$store->name, $storeUrl);
            }
        } elseif ($this->status === 'ready_for_fitting') {
            $mail->action('View Order Status', $trackingUrl);
            $mail->line('No appointment needed — just drop by the shop during opening hours.');
        } else {
            $mail->action('Track Production Progress', $trackingUrl);
        }

        return $mail->line('Thank you for trusting us with your custom tailoring!');
    }

    /**
     * Database payload — used by the NotificationBell on the frontend.
     */
    public function toArray(object $notifiable): array
    {
        $titles = $this->titles();
        $messages = $this->messages();

        $store = $this->jobOrder->store;
        $trackingUrl = $this->jobOrder->tracking_code
            ? '/track/'.$this->jobOrder->tracking_code
            : '/account/orders/'.$this->jobOrder->id;

        return [
            'type' => 'job_'.$this->status,
            'title' => $titles[$this->status] ?? 'Order Update',
            'message' => $messages[$this->status] ?? 'Your order status has been updated.',
            'action_url' => $trackingUrl,
            'job_order_id' => $this->jobOrder->id,
            'order_number' => $this->jobOrder->order_number,
            'tracking_code' => $this->jobOrder->tracking_code,
            'store' => $store ? [
                'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'logo_path' => $store->logo_path,
            ] : null,
            'actor' => $this->actor ? [
                'id' => $this->actor->id, 'name' => $this->actor->name, 'profile_picture' => $this->actor->profile_picture,
            ] : null,
        ];
    }
}
