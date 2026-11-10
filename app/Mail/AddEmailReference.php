<?php

namespace App\Mail;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;

/**
 * Every email SUTURA sends gets its own reference, e.g. "SUT-7K3P9Q":
 *  - in the subject ("… (Ref SUT-7K3P9Q)") — also stops Gmail threading several
 *    "same subject" emails into one conversation (old reset links hiding new ones);
 *  - in the footer (placeholder in resources/views/vendor/mail/{html,text}/message);
 *  - as an X-SUTURA-Ref header, and in the server log with the recipient,
 * so "I didn't get email SUT-7K3P9Q" can be looked up in Resend's logs and laravel.log.
 */
class AddEmailReference
{
    public const PLACEHOLDER = '%%SUTURA_EMAIL_REF%%';

    // No 0/O/1/I/L — easy to read out over the phone.
    private const CHARSET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function handle(MessageSending $event): void
    {
        $message = $event->message;
        if (! $message instanceof Email) {
            return;
        }

        $ref = self::generate();

        $html = $message->getHtmlBody();
        if (is_string($html)) {
            $message->html(str_replace(self::PLACEHOLDER, $ref, $html));
        }
        $text = $message->getTextBody();
        if (is_string($text)) {
            $message->text(str_replace(self::PLACEHOLDER, $ref, $text));
        }

        $message->subject(trim(($message->getSubject() ?? 'SUTURA').' (Ref '.$ref.')'));
        $message->getHeaders()->addTextHeader('X-SUTURA-Ref', $ref);

        Log::info('Email sent', [
            'ref' => $ref,
            'to' => array_map(fn ($a) => $a->getAddress(), $message->getTo()),
            'subject' => $message->getSubject(),
        ]);
    }

    public static function generate(): string
    {
        $ref = '';
        for ($i = 0; $i < 6; $i++) {
            $ref .= self::CHARSET[random_int(0, strlen(self::CHARSET) - 1)];
        }

        return 'SUT-'.$ref;
    }
}
