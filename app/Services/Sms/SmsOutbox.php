<?php

namespace App\Services\Sms;

use App\Models\SmsMessage;
use App\Models\Store;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\PlanGate;
use App\Support\SmsText;

/**
 * Every outgoing text is written here first. "auto" (the default since 2026-11-14) sends straight away (still through
 * the safety rails in SmsSender); "review" leaves it as a draft for the shop to check before anything is sent; "off"
 * keeps the outbox empty. Texts are a Pro-plan feature.
 */
class SmsOutbox
{
    public static function queue(Store $store, ?User $recipient, string $event, string $body, ?string $relatedType = null, ?int $relatedId = null, ?string $dedupe = null): ?SmsMessage
    {
        if ($store->sms_mode === 'off' || ! PlanGate::allows($store, 'pro') || ! in_array($event, SmsTemplates::enabledFor($store->sms_events), true)) {
            return null;
        }
        if ($dedupe && ($existing = SmsMessage::where('dedupe_key', $dedupe)->first())) {
            return $existing;
        }

        $body = SmsText::clean($body);
        $raw = $recipient?->phone;
        $number = PhoneNumber::normalize($raw);
        $blocked = match (true) {
            ! $recipient => 'No customer on this record.',
            $recipient->sms_opt_out => 'The customer opted out of text messages.',
            $number === null => $raw ? "\"{$raw}\" is not a valid Philippine mobile number." : 'No mobile number on file.',
            default => null,
        };

        $message = SmsMessage::create([
            'store_id' => $store->id, 'user_id' => $recipient?->id, 'event' => $event, 'related_type' => $relatedType, 'related_id' => $relatedId,
            'dedupe_key' => $dedupe, 'to_number' => $number, 'raw_number' => $raw, 'body' => $body,
            'segments' => SmsText::measure($body)['segments'],
            'status' => $blocked ? 'blocked' : ($store->sms_mode === 'auto' ? 'approved' : 'draft'),
            'blocked_reason' => $blocked,
        ]);

        return $message->status === 'approved' ? SmsSender::send($message) : $message;
    }
}
