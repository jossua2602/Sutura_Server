<?php

namespace App\Services\Sms;

use App\Models\SmsMessage;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one approved message. Three rails keep a test database from texting real people or running up a bill:
 *  1. driver "log" (the default) never leaves the server — the message is marked sent as a TEST;
 *  2. outside production a real driver only texts numbers on SMS_TEST_ALLOWLIST;
 *  3. each shop has a daily cap (SMS_DAILY_CAP).
 */
class SmsSender
{
    public static function isTestMode(): bool
    {
        return config('sms.driver', 'log') === 'log';
    }

    public static function send(SmsMessage $m): SmsMessage
    {
        if (! in_array($m->status, ['draft', 'approved', 'failed'], true)) {
            return $m;
        }
        if (! $m->to_number) {
            return self::mark($m, 'blocked', ['blocked_reason' => 'No valid mobile number.']);
        }

        $driver = config('sms.driver', 'log');
        if ($driver !== 'log' && ! app()->environment('production') && ! self::allowListed($m->to_number)) {
            return self::mark($m, 'blocked', ['blocked_reason' => 'Test environment: this number is not on the SMS allow-list, so nothing was sent.']);
        }
        $sentToday = SmsMessage::where('store_id', $m->store_id)->where('status', 'sent')->where('is_test', false)->where('sent_at', '>=', now()->startOfDay())->count();
        if ($driver !== 'log' && $sentToday >= (int) config('sms.daily_cap', 100)) {
            return self::mark($m, 'failed', ['error' => 'Daily text limit reached for this shop. It can be sent tomorrow.']);
        }

        try {
            if ($driver === 'log') {
                Log::info('SMS (TEST — not delivered)', ['to' => $m->to_number, 'segments' => $m->segments, 'body' => $m->body]);

                return self::mark($m, 'sent', ['is_test' => true, 'provider' => 'log', 'sent_at' => now()]);
            }
            if ($driver === 'semaphore') {
                $res = Http::asForm()->timeout(15)->post(config('sms.semaphore.endpoint'), array_filter([
                    'apikey' => config('sms.semaphore.api_key'),
                    'number' => ltrim($m->to_number, '+'),
                    'message' => $m->body,
                    'sendername' => config('sms.semaphore.sender_name'),
                ]));
                $first = $res->json(0) ?? [];
                if ($res->successful() && isset($first['message_id'])) {
                    return self::mark($m, 'sent', ['is_test' => false, 'provider' => 'semaphore', 'provider_message_id' => (string) $first['message_id'], 'sent_at' => now(), 'error' => null]);
                }

                return self::mark($m, 'failed', ['provider' => 'semaphore', 'error' => 'Provider refused the message: '.substr($res->body(), 0, 300)]);
            }

            if ($driver === 'iprogsms') {
                // Token in the JSON body, not the URL, so it never lands in access logs.
                $provider = config('sms.iprogsms.sms_provider');
                $res = Http::asJson()->acceptJson()->timeout(15)->post(config('sms.iprogsms.endpoint'), array_filter([
                    'api_token' => config('sms.iprogsms.api_token'),
                    'phone_number' => ltrim($m->to_number, '+'),   // 639XXXXXXXXX
                    'message' => $m->body,
                    'sms_provider' => $provider !== null && $provider !== '' ? (int) $provider : null,
                ], fn ($v) => $v !== null));
                $json = is_array($res->json()) ? $res->json() : [];
                // Their reply carries its own status: a 200 HTTP answer can still be a refusal.
                if ($res->successful() && (int) ($json['status'] ?? 0) === 200 && ! empty($json['message_id'])) {
                    return self::mark($m, 'sent', ['is_test' => false, 'provider' => 'iprogsms', 'provider_message_id' => (string) $json['message_id'], 'sent_at' => now(), 'error' => null]);
                }

                // Their "message" can be a list of errors, not just text: keep it readable either way.
                $reason = $json['message'] ?? $res->body();
                $reason = is_string($reason) ? $reason : json_encode($reason, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                Log::warning('IPROG SMS refused a message', ['http' => $res->status(), 'reply' => $json ?: substr($res->body(), 0, 500)]);

                return self::mark($m, 'failed', ['provider' => 'iprogsms', 'error' => 'Provider refused the message (HTTP '.$res->status().'): '.substr((string) $reason, 0, 300)]);
            }

            return self::mark($m, 'failed', ['error' => "Unknown SMS driver \"{$driver}\"."]);
        } catch (\Throwable $e) {
            return self::mark($m, 'failed', ['error' => 'Could not reach the SMS provider: '.$e->getMessage()]);
        }
    }

    private static function allowListed(string $number): bool
    {
        foreach ((array) config('sms.test_allowlist', []) as $allowed) {
            if (PhoneNumber::normalize($allowed) === $number) {
                return true;
            }
        }

        return false;
    }

    private static function mark(SmsMessage $m, string $status, array $extra): SmsMessage
    {
        $m->forceFill(array_merge(['status' => $status], $extra))->save();

        return $m;
    }
}
