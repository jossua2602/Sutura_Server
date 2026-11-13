<?php

namespace App\Services\Sms;

use App\Models\Appointment;
use App\Models\JobOrder;
use App\Support\SmsText;
use Carbon\Carbon;

/**
 * The few texts worth paying for. Each is written to fit ONE sms (160 plain-ASCII characters): the shop name and
 * any free text are shortened first, never the message itself. Only moments a customer must act on get a text —
 * confirmations, changes, reminders, "ready" — not every production stage.
 */
class SmsTemplates
{
    public const EVENTS = [
        'appointment_confirmed' => 'Appointment confirmed',
        'appointment_rejected' => 'Appointment declined',
        'appointment_rescheduled' => 'Appointment moved',
        'appointment_cancelled' => 'Appointment cancelled',
        'appointment_reminder' => 'Appointment reminder',
        'order_approved' => 'Order approved',
        'order_rejected' => 'Order declined',
        'order_ready_fitting' => 'Ready for fitting',
        'order_ready_pickup' => 'Ready for pickup',
    ];

    /**
     * Texts cost money and customers already get an email and an in-app notice for everything, so by default only
     * the moments where a missed message costs the shop a visit or a no-show are texted. A shop can switch any on or off.
     */
    public const DEFAULT_ENABLED = ['appointment_rescheduled', 'appointment_cancelled', 'appointment_reminder', 'order_approved', 'order_rejected', 'order_ready_fitting', 'order_ready_pickup'];

    /** @return array<int,string> */
    public static function enabledFor(?array $stored): array
    {
        return array_values(array_intersect(array_keys(self::EVENTS), $stored ?? self::DEFAULT_ENABLED));
    }

    private const KINDS = ['consultation' => 'consultation', 'measurement' => 'measurement', 'fitting' => 'fitting', 'alteration' => 'alteration', 'pickup' => 'pick-up'];

    /**
     * Texts are short and link-free (links get filtered by networks): the full details are in the
     * email. A walk-in with no real inbox is pointed to the shop instead.
     */
    private static function tail(?\App\Models\User $customer): string
    {
        return $customer && $customer->hasMailableEmail()
            ? 'Check your email for the full details.'
            : 'Visit or contact the shop for details.';
    }

    private static function when(Appointment $a): string
    {
        return $a->scheduled_at ? Carbon::parse($a->scheduled_at)->format('D M j, g:iA') : 'the set time';
    }

    private static function kind(Appointment $a): string
    {
        return $a->appointment_type === 'other' && $a->purpose_label ? SmsText::fit($a->purpose_label, 18) : (self::KINDS[$a->appointment_type] ?? 'appointment');
    }

    /** @return array{event:string, body:string, dedupe:string}|null */
    public static function appointment(Appointment $a, string $status): ?array
    {
        $shop = SmsText::shopName($a->store?->name);
        $kind = self::kind($a);
        $when = self::when($a);
        $key = 'appointment:'.$a->id;
        $tail = self::tail($a->customer);

        return match ($status) {
            'confirmed' => ['event' => 'appointment_confirmed', 'body' => "{$shop}: Good news! Your {$kind} on {$when} is confirmed. {$tail}", 'dedupe' => "{$key}:confirmed:{$when}"],
            'rejected' => ['event' => 'appointment_rejected', 'body' => "{$shop}: Sorry, we can't take your {$kind} on {$when}. {$tail}", 'dedupe' => "{$key}:rejected"],
            'rescheduled' => ['event' => 'appointment_rescheduled', 'body' => "{$shop}: Your {$kind} was moved to {$when}. {$tail}", 'dedupe' => "{$key}:rescheduled:{$when}"],
            'cancelled' => ['event' => 'appointment_cancelled', 'body' => "{$shop}: Your {$kind} on {$when} was cancelled. {$tail}", 'dedupe' => "{$key}:cancelled"],
            default => null,
        };
    }

    /** @return array{event:string, body:string, dedupe:string} */
    public static function reminder(Appointment $a): array
    {
        $shop = SmsText::shopName($a->store?->name);

        return [
            'event' => 'appointment_reminder',
            'body' => "{$shop}: Reminder, your ".self::kind($a).' is tomorrow, '.self::when($a).'. '.self::tail($a->customer),
            'dedupe' => 'appointment:'.$a->id.':reminder:'.Carbon::parse($a->scheduled_at)->toDateString(),
        ];
    }

    /** @return array{event:string, body:string, dedupe:string}|null */
    public static function order(JobOrder $job, string $stage): ?array
    {
        $shop = SmsText::shopName($job->store?->name);
        $no = SmsText::fit($job->order_number, 12);
        $tail = self::tail($job->customer);

        if ($stage === 'approved' || $stage === 'production') {
            return [
                'event' => 'order_approved',
                'body' => "{$shop}: Good news! Your order {$no} is approved. {$tail}",
                'dedupe' => 'job:'.$job->id.':approved',
            ];
        }
        if ($stage === 'rejected') {
            return [
                'event' => 'order_rejected',
                'body' => "{$shop}: Sorry, your order {$no} could not be accepted. {$tail}",
                'dedupe' => 'job:'.$job->id.':rejected',
            ];
        }
        if ($stage === 'ready_for_fitting') {
            return [
                'event' => 'order_ready_fitting',
                'body' => "{$shop}: Good news! Your order {$no} is ready for fitting. {$tail}",
                'dedupe' => 'job:'.$job->id.':fitting:'.now()->toDateString(),
            ];
        }
        if ($stage === 'ready_for_pickup') {
            return [
                'event' => 'order_ready_pickup',
                'body' => "{$shop}: Good news! Your order {$no} is ready for pick-up. {$tail}",
                'dedupe' => 'job:'.$job->id.':pickup',
            ];
        }

        return null;
    }
}
