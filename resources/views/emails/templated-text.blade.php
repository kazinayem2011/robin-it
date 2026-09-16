{{--
    The plain-text half of a templated email.

    Every other email here sends multipart/alternative, which is better for
    deliverability and is what a text-only client falls back to, so a templated
    one must not quietly become HTML-only.

    Links are the whole point of the exercise. `strip_tags` alone turns
    `<a href="https://…/reset">Reset your password</a>` into the three words
    "Reset your password" and throws the URL away — which is a password reset
    email that cannot be used, delivered to exactly the reader who has no HTML
    to fall back on. So the address is written out beside the words first.
--}}
@php
    $text = preg_replace(
        '#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
        '$3 ($2)',
        $bodyHtml,
    );

    // Block ends become line breaks, or every paragraph runs into the next.
    $text = preg_replace('#</(p|div|h[1-6]|li|tr|blockquote)>#i', "\n", $text);
    $text = preg_replace('#<br\s*/?>#i', "\n", $text);

    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
@endphp
{!! trim($text) !!}
