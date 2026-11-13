<?php

namespace App\Notifications;

use App\Models\SubscriptionUpgradeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email + bell for a plan payment made with GCash online:
 *  - paid_application : a new shop's payment went through; the application is still under review
 *  - refund_sent      : money is on its way back to the payer's GCash (or already there)
 *  - refund_failed    : the refund didn't go through; SUTURA will follow up
 * (An upgrade/renewal paid online uses the usual "Payment Confirmed" bell from PlatformPayments,
 * plus "paid" here so the owner also gets it by email.)
 */
class PlanPaymentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SubscriptionUpgradeRequest $payment,
        public string $kind,
        public ?string $detail = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $lines] = $this->content();
        $plan = $this->payment->plan?->name ?? 'SUTURA';

        $mail = (new MailMessage)
            ->subject($subject.' — '.$plan.' plan · '.($this->payment->store?->name ?? 'SUTURA'))
            ->greeting('Hello '.$notifiable->name.',');
        foreach ($lines as $line) {
            $mail->line($line);
        }
        if ($this->kind !== 'paid_application') {
            $mail->action('Open Billing', rtrim((string) config('app.frontend_url'), '/').'/dashboard/billing');
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        [$subject, $lines] = $this->content();

        return [
            'type' => 'plan_payment_'.$this->kind,
            'title' => $subject,
            'message' => $lines[0],
            'action_url' => '/dashboard/billing',
            'store_id' => $this->payment->store_id,
        ];
    }

    /** @return array{0: string, 1: list<string>} */
    private function content(): array
    {
        $p = $this->payment;
        $plan = $p->plan?->name ?? 'your';
        $amount = '₱'.number_format((float) $p->quoted_price, 2);
        $refund = '₱'.number_format((float) $p->refund_amount, 2);
        $ref = $p->payment_reference ? " (reference {$p->payment_reference})" : '';

        return match ($this->kind) {
            'paid' => ['Payment received', [
                "We received your GCash payment of {$amount} for the {$plan} plan{$ref}.",
                $this->detail ?? 'Your plan is updated.',
            ]],
            'paid_application' => ['Payment received', [
                "We received your GCash payment of {$amount} for the {$plan} plan{$ref}.",
                'Your shop application is now with the SUTURA team. We will email your shop login once it is approved.',
                'If your application is not approved, your payment will be refunded to your GCash.',
            ]],
            'refund_sent' => ['Refund sent', [
                "We are refunding {$refund} of your {$amount} GCash payment for the {$plan} plan.",
                $p->refund_status === 'succeeded'
                    ? 'The money has been returned to the GCash account you paid with.'
                    : 'It goes back to the GCash account you paid with, usually within a few days.',
                ...($p->refund_reason ? ['Reason: '.$p->refund_reason] : []),
            ]],
            'refund_failed' => ['Refund needs attention', [
                "The refund of {$refund} for your {$plan} plan payment did not go through.",
                'The SUTURA team has been told and will contact you to sort it out.',
            ]],
            default => ['Payment update', ["There is an update on your {$amount} payment for the {$plan} plan."]],
        };
    }
}
