<?php

namespace App\Services\Sms;

use App\Models\SmsMessage;
use App\Models\Store;
use App\Support\PhoneNumber;
use App\Support\SmsText;
use Illuminate\Support\Facades\Log;

/**
 * Texts SUTURA itself sends to a shop applicant (approved / declined / changes needed). Unlike a
 * shop's own texts these ignore the shop's SMS mode, plan and event switches; they still go through
 * SmsSender's rails (test mode, allow-list outside production, daily cap). Never throws: a text is
 * a courtesy next to the email, so a failure must not undo the admin's decision.
 */
class PlatformSms
{
    public const EVENTS = [
        'shop_approved' => 'Shop approved (SUTURA)',
        'shop_rejected' => 'Shop application declined (SUTURA)',
        'shop_changes_requested' => 'Shop application changes (SUTURA)',
    ];

    public static function shopDecision(Store $store, string $decision): void
    {
        try {
            $name = SmsText::shopName($store->name, 30);
            [$event, $body, $dedupe] = match ($decision) {
                'approved' => ['shop_approved', "SUTURA: Good news! Your shop {$name} is approved. Check your email for your shop login.", "store:{$store->id}:approved"],
                'rejected' => ['shop_rejected', "SUTURA: Sorry, your shop application for {$name} was not approved. Check your email for the reason.", "store:{$store->id}:rejected"],
                'changes_requested' => ['shop_changes_requested', "SUTURA: Your shop application for {$name} needs a few changes. Check your email for the details.",
                    "store:{$store->id}:changes:".($store->application?->change_requested_at?->timestamp ?? now()->timestamp)],
                default => [null, null, null],
            };
            if (! $event || SmsMessage::where('dedupe_key', $dedupe)->exists()) {
                return;
            }

            $owner = $store->owner;
            $raw = $store->application?->contact_number ?: ($owner?->phone ?: $store->phone);
            $number = PhoneNumber::normalize($raw);
            $body = SmsText::clean($body);
            $blocked = match (true) {
                (bool) $owner?->sms_opt_out => 'The owner opted out of text messages.',
                $number === null => $raw ? "\"{$raw}\" is not a valid Philippine mobile number." : 'No mobile number on file.',
                default => null,
            };

            $message = SmsMessage::create([
                'store_id' => $store->id, 'user_id' => $owner?->id, 'event' => $event, 'related_type' => 'store', 'related_id' => $store->id,
                'dedupe_key' => $dedupe, 'to_number' => $number, 'raw_number' => $raw, 'body' => $body,
                'segments' => SmsText::measure($body)['segments'],
                'status' => $blocked ? 'blocked' : 'approved',
                'blocked_reason' => $blocked,
            ]);
            if (! $blocked) {
                SmsSender::send($message);
            }
        } catch (\Throwable $e) {
            Log::warning("SUTURA text ({$decision}) for store {$store->id} failed: {$e->getMessage()}");
        }
    }
}
