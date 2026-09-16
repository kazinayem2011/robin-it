<?php

namespace App\Support;

use App\Models\EmailTemplate;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * The wording a shop has written for one of its emails.
 *
 * Until now these rows were edited, previewed and test-sent on the Message
 * Templates screen and read by nothing else: a shop could rewrite its order
 * confirmation, watch the preview show the new words, and go on sending the
 * old ones. This is the join that was missing, and the preview was the
 * dangerous half of it — it showed a change that had not happened.
 *
 * A mailable asks here first and falls back to its own Blade view. The words
 * are a nicety; the email arriving is not.
 */
class MailTemplate
{
    /**
     * What to send, or null to leave the mailable's own view alone.
     *
     * Null in three cases, each of them an email going out rather than an
     * email going wrong: no row, because the templates have never been seeded;
     * a row emptied out, which nobody does on purpose; and a row still holding
     * braces once filled, which means it names something this email cannot
     * supply — braces in a customer's inbox are worse than wording the shop
     * did not choose.
     *
     * @param  array<string, string|int|float|null>  $values
     * @return array{subject: string, data: array<string, mixed>}|null
     */
    public static function for(string $key, array $values): ?array
    {
        $template = rescue(
            fn () => EmailTemplate::where('key', $key)->first(),
            null,
            report: false,
        );

        if (! $template || trim((string) $template->body) === '') {
            return null;
        }

        $subject = MessageTemplate::fill((string) $template->subject, $values);
        $body = MessageTemplate::fill((string) $template->body, $values);

        if ($leftover = self::unfilled($subject.' '.$body)) {
            /*
             * Said out loud, because the shop cannot see it happen. Falling
             * back is correct, and it also means the words somebody wrote are
             * being ignored from now on while the preview goes on showing
             * them — without this there is nothing at all to find.
             */
            Log::warning('Email template ignored: '.$key.' still contains '.$leftover.' after filling.');

            return null;
        }

        return [
            'subject' => $subject,
            'data' => [
                'title' => $subject,
                'preheader' => null,
                'bodyHtml' => $body,
            ],
        ];
    }

    /**
     * The line-item table `{order_items}` stands for.
     *
     * Markup rather than words, which is why it is a placeholder and not
     * something anybody types: a rich text editor handed this would take it
     * apart, and the inline styles are what Outlook needs to draw a table at
     * all. The shop decides where it goes; this decides what it looks like.
     *
     * Shared with the preview, so what a staff member checks on the Message
     * Templates screen is the same table the customer is sent.
     *
     * @param  iterable<array{0: string, 1: string|int, 2: string}>  $rows
     */
    public static function itemsTable(iterable $rows): string
    {
        $html = '<table role="presentation" cellpadding="8" cellspacing="0" border="0" width="100%"'
            .' style="border-collapse:collapse; margin:0 0 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">';

        foreach ($rows as [$name, $quantity, $line]) {
            $html .= '<tr>'
                .'<td style="border-bottom:1px solid #e2e8f0; color:#334155;">'.e($name).'</td>'
                .'<td style="border-bottom:1px solid #e2e8f0; color:#64748b; text-align:center;">×'.e((string) $quantity).'</td>'
                .'<td style="border-bottom:1px solid #e2e8f0; color:#0f172a; text-align:right; white-space:nowrap;">'.e($line).'</td>'
                .'</tr>';
        }

        return $html.'</table>';
    }

    /**
     * The first placeholder left standing, if any.
     *
     * Every value an email can fill is supplied in one go, so one surviving
     * means the template names something this email does not have — and a
     * name is what makes the warning worth reading.
     */
    private static function unfilled(string $text): ?string
    {
        return preg_match('/\{[a-z_]+\}/', $text, $found) ? $found[0] : null;
    }
}
