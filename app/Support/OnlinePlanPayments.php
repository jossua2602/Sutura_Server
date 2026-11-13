<?php

namespace App\Support;

use App\Models\Store;
use App\Models\SubscriptionUpgradeRequest;
use App\Notifications\PlanPaymentNotification;
use App\Services\GatewayException;
use App\Services\Xendit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Plan payments made with GCash online (channel "online"), start to finish:
 *
 *  checkout() → the payer is sent to a GCash checkout page
 *  sync()     → asks the gateway whether it was paid; called by the webhook, and by the
 *               return page so it also works where the webhook can't reach (localhost)
 *  refund()   → an admin sends money back to the payer's GCash
 *
 * The gateway's answer is always fetched from its API with our secret key; a webhook body
 * is only ever a hint of *which* payment to re-check, so a forged webhook can't mark anything
 * paid. Paid upgrades/renewals start right away (PlatformPayments::approveOnline); a new
 * shop's payment is marked paid and still waits for the application decision.
 */
class OnlinePlanPayments
{
    /** Returns the GCash checkout URL for this payment (reusing a still-valid one). */
    public static function checkout(SubscriptionUpgradeRequest $payment, string $returnPath): string
    {
        if (! Xendit::configured()) {
            throw ValidationException::withMessages(['payment' => 'Paying with GCash online is not available right now. Upload a GCash receipt instead.']);
        }
        if (! $payment->isOnline() || $payment->status !== 'pending') {
            throw ValidationException::withMessages(['payment' => 'This payment is already closed.']);
        }
        if ($payment->gateway_status === 'paid') {
            throw ValidationException::withMessages(['payment' => 'This payment is already paid.']);
        }

        // An earlier checkout link: reuse it while fresh, else make sure it can't be paid any more.
        if ($payment->gateway_invoice_id) {
            if ($payment->gateway_status === 'awaiting' && $payment->updated_at?->gt(now()->subHours(23))) {
                return $payment->gateway_checkout_url;
            }
            $old = Xendit::getInvoice($payment->gateway_invoice_id);
            if (self::isPaid($old)) {
                self::apply($payment, $old);
                throw ValidationException::withMessages(['payment' => 'This payment is already paid.']);
            }
            if (strtoupper((string) ($old['status'] ?? '')) === 'PENDING') {
                Xendit::expireInvoice($payment->gateway_invoice_id);
            }
        }

        $base = rtrim((string) config('app.frontend_url'), '/').$returnPath;
        $sep = str_contains($returnPath, '?') ? '&' : '?';
        $store = $payment->store;
        $invoice = Xendit::createInvoice(
            'sutura-plan-'.$payment->id.'-'.Str::lower(Str::random(6)),
            (float) $payment->quoted_price,
            'SUTURA '.($payment->plan?->name ?? '').' plan ('.$payment->billing_cycle.')'.($store ? ' — '.$store->name : ''),
            $base.$sep.'gcash=success',
            $base.$sep.'gcash=failed',
        );

        $payment->forceFill([
            'gateway_invoice_id' => $invoice['id'],
            'gateway_checkout_url' => $invoice['invoice_url'],
            'gateway_status' => 'awaiting',
        ])->save();
        $payment->log('checkout_opened', null, 'GCash checkout opened.');

        return $invoice['invoice_url'];
    }

    /** Re-checks the payment with the gateway and applies the result. */
    public static function sync(SubscriptionUpgradeRequest $payment): SubscriptionUpgradeRequest
    {
        if ($payment->isOnline() && $payment->gateway_invoice_id && $payment->gateway_status !== 'paid') {
            self::apply($payment, Xendit::getInvoice($payment->gateway_invoice_id));
        }
        if ($payment->refund_status === 'pending' && $payment->refund_gateway_id) {
            self::syncRefund($payment);
        }

        return $payment->refresh();
    }

    /** @param array<string, mixed> $invoice the gateway's own record (fetched with our key) */
    private static function apply(SubscriptionUpgradeRequest $payment, array $invoice): void
    {
        if (($invoice['id'] ?? null) !== $payment->gateway_invoice_id) {
            return;
        }

        if (! self::isPaid($invoice)) {
            if (strtoupper((string) ($invoice['status'] ?? '')) === 'EXPIRED' && $payment->gateway_status === 'awaiting') {
                $payment->forceFill(['gateway_status' => 'expired'])->save();
            }

            return;
        }

        $paid = (float) ($invoice['paid_amount'] ?? $invoice['amount'] ?? 0);
        if (($invoice['currency'] ?? 'PHP') !== 'PHP' || $paid + 0.009 < (float) $payment->quoted_price) {
            logger()->warning('GCash payment amount mismatch', ['payment_id' => $payment->id, 'paid' => $paid, 'due' => (float) $payment->quoted_price]);
            $payment->log('needs_review', null, 'GCash reported a payment of ₱'.number_format($paid, 2).', not the plan price. An admin needs to check it.');

            return;
        }

        $justPaid = DB::transaction(function () use ($payment, $invoice, $paid) {
            $row = SubscriptionUpgradeRequest::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($row->gateway_status === 'paid') {
                return false;
            }
            $reference = (string) ($invoice['payment_id'] ?? $invoice['id']);
            $row->forceFill([
                'gateway_status' => 'paid',
                'gateway_paid_at' => isset($invoice['paid_at']) ? Carbon::parse($invoice['paid_at'])->setTimezone(config('app.timezone')) : now(),
                'amount_sent' => $paid,
                'payment_method' => 'gcash',
                'payment_reference' => $reference,
                'reference_key' => SubscriptionUpgradeRequest::referenceKey($reference),
                'paid_at_reported' => isset($invoice['paid_at']) ? Carbon::parse($invoice['paid_at'])->setTimezone(config('app.timezone')) : now(),
            ])->save();
            $row->log('paid_online', null, 'Paid ₱'.number_format($paid, 2).' with GCash.');

            return true;
        });

        if (! $justPaid) {
            return;
        }

        $payment->refresh();
        if ($payment->type === 'new_shop') {
            self::tell($payment, 'paid_application');

            return;
        }

        $message = PlatformPayments::approveOnline(request(), $payment);
        $payment->refresh();
        self::tell($payment, 'paid', $message
            ? self::ownerSummary($payment)
            : 'Your plan will be updated once the SUTURA team checks one detail. You will get another email when it is done.');
    }

    /**
     * Sends money back to the payer's GCash. Full or partial, once per payment
     * (a failed refund can be tried again). Doesn't change the shop's plan.
     *
     * @param  string  $reasonCode  CANCELLATION | DUPLICATE | REQUESTED_BY_CUSTOMER | OTHERS
     */
    public static function refund(Request $request, SubscriptionUpgradeRequest $payment, float $amount, string $reasonCode, string $reason): SubscriptionUpgradeRequest
    {
        $amount = round($amount, 2);

        // Claim the refund first (row lock), so two clicks can't both send money back.
        DB::transaction(function () use ($payment, $amount, $reason, $request) {
            $row = SubscriptionUpgradeRequest::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! $row->refundable()) {
                throw ValidationException::withMessages(['refund' => $row->refund_status ? 'This payment has already been refunded.' : 'Only a GCash payment made online can be refunded here.']);
            }
            $max = (float) ($row->amount_sent ?? $row->quoted_price);
            if ($amount <= 0 || $amount > $max + 0.009) {
                throw ValidationException::withMessages(['amount' => 'The refund must be between ₱1 and ₱'.number_format($max, 2).'.']);
            }
            $row->forceFill([
                'refund_amount' => $amount, 'refund_status' => 'pending', 'refund_reason' => $reason,
                'refunded_by' => $request->user()->id, 'refund_gateway_id' => null, 'refunded_at' => null,
            ])->save();
        });

        try {
            $result = Xendit::createRefund($payment->gateway_invoice_id, 'sutura-refund-'.$payment->id.'-'.now()->format('YmdHis'), $amount, $reasonCode);
        } catch (\Throwable $e) {
            // Not sent (or no answer): free the payment so the admin can try again.
            $why = $e instanceof GatewayException ? $e->getMessage() : 'the payment service could not be reached.';
            $payment->forceFill(['refund_status' => 'failed'])->save();
            $payment->log('refund_failed', $request->user()->id, 'GCash did not accept the refund: '.$why);
            throw ValidationException::withMessages(['refund' => 'GCash did not accept the refund: '.$why]);
        }

        $status = self::refundStatus($result);
        $payment->forceFill([
            'refund_gateway_id' => $result['id'] ?? null,
            'refund_status' => $status,
            'refunded_at' => $status === 'succeeded' ? now() : null,
        ])->save();
        $payment->log($status === 'succeeded' ? 'refunded' : 'refund_requested', $request->user()->id, '₱'.number_format($amount, 2).' — '.$reason);

        $store = $payment->store;
        AdminAudit::record($request, 'platform_payment_refunded', Store::class, $store?->id ?? 0, ($store?->name ?? 'Shop').' (₱'.number_format($amount, 2).')', $store?->id, $reason);

        if ($status === 'failed') {
            throw ValidationException::withMessages(['refund' => 'GCash could not complete the refund. Try again later.']);
        }
        self::tell($payment->refresh(), 'refund_sent');

        return $payment;
    }

    /** A refund that was still processing: ask the gateway again. */
    public static function syncRefund(SubscriptionUpgradeRequest $payment): void
    {
        if ($payment->refund_status !== 'pending' || ! $payment->refund_gateway_id) {
            return;
        }
        $status = self::refundStatus(Xendit::getRefund($payment->refund_gateway_id));
        if ($status === 'pending') {
            return;
        }

        $changed = DB::transaction(function () use ($payment, $status) {
            $row = SubscriptionUpgradeRequest::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($row->refund_status !== 'pending') {
                return false;
            }
            $row->forceFill(['refund_status' => $status, 'refunded_at' => $status === 'succeeded' ? now() : null])->save();
            $row->log($status === 'succeeded' ? 'refunded' : 'refund_failed', null, $status === 'succeeded' ? 'The refund reached the payer’s GCash.' : 'GCash could not complete the refund.');

            return true;
        });

        if ($changed) {
            self::tell($payment->refresh(), $status === 'succeeded' ? 'refund_sent' : 'refund_failed');
        }
    }

    /** @param array<string, mixed> $invoice */
    private static function isPaid(array $invoice): bool
    {
        return in_array(strtoupper((string) ($invoice['status'] ?? '')), ['PAID', 'SETTLED'], true);
    }

    /** @param array<string, mixed> $refund */
    private static function refundStatus(array $refund): string
    {
        return match (strtoupper((string) ($refund['status'] ?? ''))) {
            'SUCCEEDED' => 'succeeded',
            'FAILED', 'CANCELLED' => 'failed',
            default => 'pending',
        };
    }

    private static function ownerSummary(SubscriptionUpgradeRequest $payment): string
    {
        $plan = $payment->plan?->name ?? 'new';
        $start = $payment->billing_period_start?->timezone('Asia/Manila');
        $end = $payment->billing_period_end?->timezone('Asia/Manila')->format('M j, Y');
        if ($payment->type === 'downgrade' && $start?->isFuture()) {
            return "Your shop moves to the {$plan} plan on {$start->format('M j, Y')}.";
        }

        return $end ? "Your {$plan} plan is active until {$end}." : "Your {$plan} plan is active.";
    }

    /** Email + bell for the person who paid; a failed email never undoes the payment. */
    private static function tell(SubscriptionUpgradeRequest $payment, string $kind, ?string $detail = null): void
    {
        try {
            $payment->requester?->notify(new PlanPaymentNotification($payment, $kind, $detail));
        } catch (\Throwable $e) {
            logger()->warning("Plan payment {$payment->id} email failed: {$e->getMessage()}");
        }
    }
}
