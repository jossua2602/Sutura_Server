<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * The order box at the top of every order email: order number, tracking code and
 * shop, so the customer can match the email to their order at a glance (and quote
 * it to the shop). Inline styles only — email apps drop <style> blocks unevenly.
 * Used as a MailMessage line: ->line(OrderMailBox::make('ORD-0014', 'AATAT2GTT3', 'Thread & Needle')).
 */
class OrderMailBox
{
    public static function make(string $orderNumber, ?string $trackingCode = null, ?string $storeName = null): HtmlString
    {
        $label = 'font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#8a7e73;padding:0 0 2px;';
        $value = 'font-size:16px;font-weight:bold;color:#2d2a26;padding:0;font-family:Georgia,\'Times New Roman\',serif;';

        // Codes never break across lines ("ORD-" / "0018"); only the shop name may wrap.
        $code = $value.'white-space:nowrap;';
        $cells = [self::cell('Order', $orderNumber, $label, $code)];
        if ($trackingCode) {
            $cells[] = self::cell('Tracking code', $trackingCode, $label, $code);
        }
        if ($storeName) {
            $cells[] = self::cell('Shop', $storeName, $label, $value);
        }

        // One line of HTML: the mail Markdown parser would turn indented lines into a code block.
        return new HtmlString(
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:#f3ede4;border:1px solid #e3d9cc;border-radius:8px;">'
            .'<tr><td style="padding:14px 16px;"><table role="presentation" cellpadding="0" cellspacing="0"><tr>'
            .implode('<td style="width:24px;"></td>', $cells)
            .'</tr></table></td></tr></table>'
        );
    }

    private static function cell(string $label, string $value, string $labelStyle, string $valueStyle): string
    {
        return '<td style="vertical-align:top;padding:0;">'
            .'<div style="'.$labelStyle.'">'.e($label).'</div>'
            .'<div style="'.$valueStyle.'">'.e($value).'</div>'
            .'</td>';
    }
}
