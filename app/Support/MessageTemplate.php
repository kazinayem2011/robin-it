<?php

namespace App\Support;

/**
 * Filling a template in, and refusing to save one somebody has broken.
 *
 * Substitution is `{name}` → value, deliberately not Blade. These strings are
 * written by shop staff in a browser; running them through a template engine
 * would mean anything typed into the box can execute, and the box is reachable
 * by anyone with the `settings` ability.
 *
 * The check on the way in is the part worth having. A placeholder edited in a
 * rich text editor is just text: click into the middle of `{customer_name}`,
 * type, and it quietly becomes something that will never be filled in. Nobody
 * notices, because the editor shows what was typed and the template still
 * saves — the shop finds out when a customer receives "Hi {customer_nam e},".
 */
class MessageTemplate
{
    /**
     * Replace every `{placeholder}` the values cover.
     *
     * Anything not supplied is left as it was written rather than blanked: an
     * empty space where a name should be reads as a bug to the person who
     * receives it, and as nothing at all to the person who sent it.
     *
     * @param  array<string, string|int|float|null>  $values
     */
    public static function fill(string $body, array $values): string
    {
        foreach ($values as $name => $value) {
            if ($value === null) {
                continue;
            }

            $body = str_replace('{'.$name.'}', (string) $value, $body);
        }

        return $body;
    }

    /**
     * Placeholders the template declared that the text no longer contains.
     *
     * Only ones it declared. A shop is free to drop wording it does not want,
     * and free to leave a placeholder out on purpose — but `variables` is what
     * this template was written to fill in, so losing one is far more often a
     * typo inside the braces than a decision.
     *
     * @param  list<string>  $declared
     * @return list<string>
     */
    public static function missing(array $declared, ?string $body): array
    {
        if ($body === null || $declared === []) {
            return [];
        }

        /*
         * The body exactly as stored, with nothing stripped and nothing
         * decoded — because that is the string `fill()` runs `str_replace`
         * over, and the only question worth asking is whether it will find the
         * placeholder there.
         *
         * Stripping tags first looks sensible and is the opposite of what is
         * wanted. An editor splits a placeholder across elements —
         * `{customer_<strong></strong>name}` — and `strip_tags` puts it back
         * together, so the check passes while the substitution still fails and
         * the customer receives the braces. Decoding entities has the same
         * fault: `&#123;customer_name&#125;` reads as intact once decoded and
         * is never substituted as stored.
         */
        return array_values(array_filter(
            $declared,
            fn (string $name) => ! str_contains($body, '{'.$name.'}'),
        ));
    }

    /** The message that names what was broken, in the words of the person who broke it. */
    public static function complaint(array $missing): string
    {
        if (count($missing) === 1) {
            return "The {{$missing[0]}} placeholder is missing or broken. "
                .'Put it back, or it will not be filled in.';
        }

        return 'These placeholders are missing or broken: '
            .implode(', ', array_map(fn ($name) => '{'.$name.'}', $missing))
            .'. Put them back, or they will not be filled in.';
    }
}
