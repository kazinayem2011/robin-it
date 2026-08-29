<?php

namespace App\Support;

use App\Models\Coupon;
use App\Models\Product;

/**
 * Turning a written campaign into the thing that actually gets sent.
 *
 * Somebody writing a promotion wants to drop a product or a coupon into the
 * middle of a sentence without composing a price table by hand. The obvious way
 * is to paste styled HTML into the message box, which fails here twice: the
 * sanitiser strips inline styles on the way in — correctly, since a style
 * attribute is a way to smuggle script back in — and a message written as HTML
 * for an email is unreadable when the same campaign is sent as a text.
 *
 * So the box holds a short token instead, and the rendering happens here, once
 * per channel:
 *
 *   [[product:rtx-4090]]     the item, its price, and where to buy it
 *   [[coupon:EID15]]         the code and what it takes off
 *   [[deal:rtx-4090:EID15]]  both, with the price after the code worked out
 *
 * Which means the same campaign reads properly whether it goes out as an email
 * or a text, and switching between the two after writing it changes nothing.
 */
class CampaignContent
{
    /** What the compose screen offers, and what each one is for. */
    public const TOKENS = [
        'product' => 'A product, its price and a link to it',
        'coupon' => 'A discount code and what it takes off',
        'deal' => 'A product with a code applied, showing the price after it',
    ];

    private const PATTERN = '/\[\[(product|coupon|deal):([^\]]+)\]\]/';

    /**
     * The body as an email.
     *
     * The written part is already sanitised; what this adds is the shop's own
     * markup, so it is built here rather than accepted from the box.
     */
    public static function html(string $body): string
    {
        return preg_replace_callback(
            self::PATTERN,
            fn ($m) => self::block($m[1], explode(':', $m[2]), true),
            $body
        ) ?? $body;
    }

    /**
     * The body as a text message, or as the plain-text half of an email.
     */
    public static function text(string $body): string
    {
        $rendered = preg_replace_callback(
            self::PATTERN,
            fn ($m) => self::block($m[1], explode(':', $m[2]), false),
            $body
        ) ?? $body;

        // Whatever markup the writer used goes; a gateway charges for every
        // character of it and shows none of them.
        return trim(html_entity_decode(strip_tags($rendered)));
    }

    /**
     * Whether every token in a body points at something that still exists.
     *
     * A product delisted between writing a campaign and sending it would
     * otherwise go out as a broken sentence to the whole list.
     *
     * @return array<int, string> what could not be found
     */
    public static function missing(string $body): array
    {
        preg_match_all(self::PATTERN, $body, $matches, PREG_SET_ORDER);

        $missing = [];

        foreach ($matches as $match) {
            $parts = explode(':', $match[2]);

            if ($match[1] !== 'coupon' && ! self::product($parts[0])) {
                $missing[] = "product \"{$parts[0]}\"";
            }

            if ($match[1] === 'coupon' && ! self::coupon($parts[0])) {
                $missing[] = "coupon \"{$parts[0]}\"";
            }

            if ($match[1] === 'deal' && isset($parts[1]) && ! self::coupon($parts[1])) {
                $missing[] = "coupon \"{$parts[1]}\"";
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @param  array<int, string>  $parts
     */
    private static function block(string $kind, array $parts, bool $asHtml): string
    {
        return match ($kind) {
            'product' => self::productBlock(self::product($parts[0]), null, $asHtml),
            'coupon' => self::couponBlock(self::coupon($parts[0]), $asHtml),
            'deal' => self::productBlock(
                self::product($parts[0]),
                isset($parts[1]) ? self::coupon($parts[1]) : null,
                $asHtml
            ),
            default => '',
        };
    }

    private static function productBlock(?Product $product, ?Coupon $coupon, bool $asHtml): string
    {
        if (! $product) {
            // Nothing rather than a broken token: the campaign still reads as
            // a sentence, and missing() has already warned before sending.
            return '';
        }

        $was = (float) $product->price;
        $now = (float) ($product->discount_price ?: $product->price);

        if ($coupon) {
            $now = self::afterCoupon($now, $coupon);
        }

        $url = rtrim(config('app.url'), '/').'/products/'.$product->slug;
        $saving = $was > $now;

        if (! $asHtml) {
            /*
             * A plain hyphen, not an em dash.
             *
             * An em dash is outside the 7-bit alphabet, so one of them pushes
             * the whole message into unicode where 70 characters fit instead of
             * 160 — doubling what the shop pays. This template goes into every
             * text campaign that mentions a product, so the typographically
             * nicer character would have been a standing surcharge on all of
             * them, added by the shop's own software rather than by the writer.
             */
            $line = $product->name.' - Tk '.number_format($now, 0);

            if ($saving) {
                $line .= ' (was Tk '.number_format($was, 0).')';
            }

            if ($coupon) {
                $line .= ' with code '.$coupon->code;
            }

            return "\n".$line."\n".$url."\n";
        }

        $price = $saving
            ? '<span style="text-decoration:line-through;color:#94a3b8;margin-right:10px;">Tk '
                .number_format($was, 0).'</span><strong style="color:#d12127;font-size:22px;">Tk '
                .number_format($now, 0).'</strong>'
            : '<strong style="color:#d12127;font-size:22px;">Tk '.number_format($now, 0).'</strong>';

        $code = $coupon
            ? '<p style="margin:12px 0 0;font-size:14px;color:#334155;">with code '
                .'<strong style="background:#0f172a;color:#fff;padding:3px 10px;border-radius:5px;">'
                .e($coupon->code).'</strong></p>'
            : '';

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"'
            .' style="margin:22px 0;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;">'
            .'<tr><td style="padding:22px;text-align:center;font-family:Arial,Helvetica,sans-serif;">'
            .'<h3 style="margin:0 0 12px;font-size:18px;color:#0f172a;">'.e($product->name).'</h3>'
            .'<div>'.$price.'</div>'.$code
            .'<div style="margin-top:18px;"><a href="'.e($url).'"'
            .' style="display:inline-block;padding:11px 26px;background:#d12127;color:#ffffff;'
            .'text-decoration:none;border-radius:6px;font-weight:bold;">Shop now</a></div>'
            .'</td></tr></table>';
    }

    private static function couponBlock(?Coupon $coupon, bool $asHtml): string
    {
        if (! $coupon) {
            return '';
        }

        // 'percent', which is what the column stores — not 'percentage'.
        $off = $coupon->discount_type === 'percent'
            ? rtrim(rtrim(number_format((float) $coupon->discount_value, 2, '.', ''), '0'), '.').'%'
            : 'Tk '.number_format((float) $coupon->discount_value, 0);

        $until = $coupon->expires_at
            ? ' Until '.$coupon->expires_at->format('j M').'.'
            : '';

        if (! $asHtml) {
            return "\nUse code ".$coupon->code.' for '.$off.' off.'.$until."\n";
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"'
            .' style="margin:22px 0;border:2px dashed #d12127;border-radius:10px;">'
            .'<tr><td style="padding:20px;text-align:center;font-family:Arial,Helvetica,sans-serif;">'
            .'<p style="margin:0 0 8px;font-size:13px;color:#64748b;">Use this code at checkout</p>'
            .'<div style="font-size:24px;font-weight:bold;letter-spacing:2px;color:#0f172a;">'
            .e($coupon->code).'</div>'
            .'<p style="margin:10px 0 0;font-size:15px;color:#334155;"><strong>'.e($off)
            .' off</strong> your order.'.e($until).'</p>'
            .'</td></tr></table>';
    }

    /**
     * The price once a code is applied.
     *
     * Asked of the coupon rather than worked out here. It already owns this —
     * checkout recalculates through the same method rather than trusting the
     * browser — and it honours the ceiling: a "20% off up to Tk 2,000" code
     * advertised as Tk 9,000 off a build is a promise the checkout will refuse
     * to keep, which is worse than not advertising it at all.
     */
    private static function afterCoupon(float $price, Coupon $coupon): float
    {
        return max(0.0, round($price - $coupon->discountFor($price), 2));
    }

    private static function product(string $slug): ?Product
    {
        return Product::where('slug', trim($slug))->where('is_active', true)->first();
    }

    private static function coupon(string $code): ?Coupon
    {
        return Coupon::where('code', trim($code))->where('is_active', true)->first();
    }
}
