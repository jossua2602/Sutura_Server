<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The payment gateway behind "Pay with GCash" (plan payments only).
 *
 * A hosted checkout page (Invoice API) limited to GCash, plus refunds. The secret key
 * stays on the server; nothing from here is ever sent to the browser except the
 * checkout page URL. Users never see the gateway's name: the UI just says "GCash".
 */
class Xendit
{
    public static function configured(): bool
    {
        return filled(config('services.xendit.secret_key'));
    }

    /** True for test keys: payments are simulated and no real money moves. */
    public static function testMode(): bool
    {
        return str_starts_with((string) config('services.xendit.secret_key'), 'xnd_development_');
    }

    /**
     * Opens a GCash checkout.
     *
     * @return array{id: string, invoice_url: string, status: string}
     */
    public static function createInvoice(string $externalId, float $amount, string $description, string $successUrl, string $failureUrl): array
    {
        return self::send('post', '/v2/invoices', [
            'external_id' => $externalId,
            'amount' => round($amount, 2),
            'currency' => 'PHP',
            'description' => $description,
            'payment_methods' => ['GCASH'],
            'invoice_duration' => 86400, // the checkout link works for 24 hours
            'success_redirect_url' => $successUrl,
            'failure_redirect_url' => $failureUrl,
        ]);
    }

    /** @return array<string, mixed> status: PENDING | PAID | SETTLED | EXPIRED */
    public static function getInvoice(string $id): array
    {
        return self::send('get', '/v2/invoices/'.rawurlencode($id));
    }

    /** Stops an unpaid checkout link from being used. */
    public static function expireInvoice(string $id): void
    {
        self::send('post', '/invoices/'.rawurlencode($id).'/expire!');
    }

    /**
     * Sends money back to the payer's GCash.
     *
     * @param  string  $reason  DUPLICATE | CANCELLATION | REQUESTED_BY_CUSTOMER | OTHERS
     * @return array<string, mixed> status: PENDING | SUCCEEDED | FAILED
     */
    public static function createRefund(string $invoiceId, string $referenceId, float $amount, string $reason): array
    {
        return self::send('post', '/refunds', [
            'invoice_id' => $invoiceId,
            'reference_id' => $referenceId,
            'amount' => round($amount, 2),
            'currency' => 'PHP',
            'reason' => $reason,
        ], ['Idempotency-key' => $referenceId]);
    }

    /** @return array<string, mixed> */
    public static function getRefund(string $id): array
    {
        return self::send('get', '/refunds/'.rawurlencode($id));
    }

    /** Webhooks carry the dashboard's verification token; compare in constant time. */
    public static function validCallbackToken(?string $token): bool
    {
        $expected = (string) config('services.xendit.callback_token');

        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /** @return array<string, mixed> */
    private static function send(string $method, string $path, array $body = [], array $headers = []): array
    {
        if (! self::configured()) {
            throw new RuntimeException('Online GCash payments are not set up yet.');
        }

        $response = self::client()->withHeaders($headers)->{$method}($path, $method === 'get' ? null : $body);

        if ($response->failed()) {
            // Log the gateway's own message (never the key); the user gets a plain one.
            logger()->warning('Payment gateway error', ['path' => $path, 'status' => $response->status(), 'error' => $response->json('error_code'), 'message' => $response->json('message')]);
            throw new GatewayException($response->json('message') ?: 'The payment service did not respond.', $response->status(), $response->json('error_code'));
        }

        return $response->json() ?? [];
    }

    private static function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.xendit.base_url'), '/'))
            ->withBasicAuth((string) config('services.xendit.secret_key'), '')
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->connectTimeout(10);
    }
}
