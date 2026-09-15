<?php

namespace App\Support;

/**
 * Plausible values to fill a template with while somebody is writing it.
 *
 * Invented rather than drawn from a real order, and deliberately so: the shop
 * has four orders and a staff member previewing a refund message should not be
 * shown a customer's name and the sum they are owed. These are the shape of the
 * real thing — an order number that looks like an order number, a total with a
 * separator in it — so what the preview shows is the length and the line breaks
 * the real message will have.
 *
 * Length is the point for SMS. The gateway charges by the part, and a filled
 * `{order_number}` is six characters shorter than the placeholder that stood
 * there, so counting the template rather than the message overstates the cost
 * of every one of them.
 */
class TemplateSamples
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $shop = BrandDetails::name();

        return [
            'shop_name' => $shop,
            'shop_url' => url('/'),
            'customer_name' => 'Rahim Uddin',

            'order_number' => 'ORD-24081',
            'order_total' => 'Tk 84,500',
            'order_status' => 'Dispatched',
            'order_url' => url('/track'),
            'amount_due' => '84,500',
            'amount' => '12,000',
            'track_url' => url('/track'),
            'courier_name' => 'Pathao',

            'product_name' => 'ASUS TUF Gaming A15',
            'product_url' => url('/shop'),

            'enquiry_subject' => 'Is the RTX 4060 model in stock?',
            'reply_body' => '<p>Yes — we have three in the Uttara branch and can hold '
                .'one for you until Thursday.</p>',

            'reset_url' => url('/password/reset/sample-token'),
            'verify_url' => url('/email/verify/sample-token'),
            'expires_minutes' => '60',

            /*
             * The one placeholder that is not a string. It draws the line-item
             * table, which is why it cannot be typed by hand — an editor would
             * strip the markup email clients need. Shown here as it will be
             * sent, so the preview is not misleading about the length.
             */
            'order_items' => self::itemsTable(),
        ];
    }

    /** Only the names, for the chips the editor offers. */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    private static function itemsTable(): string
    {
        $rows = [
            ['ASUS TUF Gaming A15 FA507NU', '1', 'Tk 84,500'],
            ['Logitech G304 Wireless Mouse', '2', 'Tk 7,000'],
        ];

        $html = '<table role="presentation" cellpadding="8" cellspacing="0" border="0" width="100%"'
            .' style="border-collapse:collapse; margin:0 0 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">';

        foreach ($rows as [$name, $qty, $line]) {
            $html .= '<tr>'
                .'<td style="border-bottom:1px solid #e2e8f0; color:#334155;">'.e($name).'</td>'
                .'<td style="border-bottom:1px solid #e2e8f0; color:#64748b; text-align:center;">×'.e($qty).'</td>'
                .'<td style="border-bottom:1px solid #e2e8f0; color:#0f172a; text-align:right; white-space:nowrap;">'.e($line).'</td>'
                .'</tr>';
        }

        return $html.'</table>';
    }
}
