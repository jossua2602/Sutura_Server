<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionUpgradeRequest;
use App\Services\Xendit;
use App\Support\OnlinePlanPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/xendit — the payment gateway telling SUTURA a GCash checkout was paid
 * or expired, or a refund finished. Set this URL in the gateway dashboard for invoices
 * and refunds.
 *
 * Two checks: the verification token must match (header X-CALLBACK-TOKEN), and the body
 * is never trusted — it only says *which* payment to re-check; the status itself is
 * fetched from the gateway with our secret key (OnlinePlanPayments::sync).
 * Unknown ids get a 200 so the gateway's "test webhook" button and retries don't loop.
 */
class GcashWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! Xendit::validCallbackToken($request->header('x-callback-token'))) {
            return response()->json(['message' => 'Invalid callback token.'], 401);
        }

        $event = (string) $request->input('event', '');
        $payment = str_starts_with($event, 'refund.')
            ? $this->byRefund((string) $request->input('data.id'))
            : $this->byInvoice((string) $request->input('id'));

        if ($payment) {
            try {
                OnlinePlanPayments::sync($payment);
            } catch (\Throwable $e) {
                // 5xx → the gateway retries later.
                logger()->error('GCash webhook processing failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);

                return response()->json(['message' => 'Try again later.'], 503);
            }
        }

        return response()->json(['received' => true]);
    }

    private function byInvoice(string $id): ?SubscriptionUpgradeRequest
    {
        return $id === '' ? null : SubscriptionUpgradeRequest::where('gateway_invoice_id', $id)->first();
    }

    private function byRefund(string $id): ?SubscriptionUpgradeRequest
    {
        return $id === '' ? null : SubscriptionUpgradeRequest::where('refund_gateway_id', $id)->first();
    }
}
