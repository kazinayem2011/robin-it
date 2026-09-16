{{--
    The plain-text half of a templated email.

    Every other email here sends multipart/alternative, which is better for
    deliverability and is what a text-only client falls back to; a templated
    one should not quietly become HTML-only. The words are the shop's markup,
    so they are stripped back to text rather than written twice.
--}}
{!! trim(preg_replace('/\n{3,}/', "\n\n", html_entity_decode(strip_tags(preg_replace('#</(p|div|h[1-6]|li|tr)>#i', "\n", $bodyHtml))))) !!}
