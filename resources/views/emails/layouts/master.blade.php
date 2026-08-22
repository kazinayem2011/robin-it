{{--
    Base email layout.

    Built for real mail clients rather than a browser:
      * Tables with role="presentation" — Outlook renders through Word, which
        does not honour max-width on a <div>, so a div-based shell stretches
        edge to edge there.
      * Styles are inline on each element. The <style> block below carries only
        progressive enhancements (mobile, dark mode); clients that strip it
        still get the full design.
      * MSO conditionals pin the 600px width for Outlook.
      * A preheader controls the inbox preview line instead of leaving the
        client to grab whatever text comes first.

    Slots: $title, $preheader, and the `content` section.
--}}
@php($brand = \App\Support\BrandDetails::all())
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $title ?? $brand['name'].' Notification' }}</title>

    <!--[if mso]>
    <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
    <![endif]-->

    <style>
        /* Progressive enhancement only — every element is inline-styled too. */
        body { margin:0 !important; padding:0 !important; width:100% !important; }
        table { border-collapse:collapse !important; }
        img { border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic; }
        a { text-decoration:none; }

        /* Stop Apple/Outlook auto-linking phone numbers and addresses in grey. */
        .appleLinksGrey a { color:#94a3b8 !important; text-decoration:none !important; }

        @media only screen and (max-width:620px) {
            .eml-container { width:100% !important; }
            .eml-pad { padding-left:20px !important; padding-right:20px !important; }
            .eml-h1 { font-size:22px !important; line-height:30px !important; }
            .eml-stack { display:block !important; width:100% !important; text-align:left !important; }
            .eml-hide-sm { display:none !important; }
            .eml-btn a { display:block !important; width:auto !important; }
        }

        /*
         * Dark mode. Every element carries an inline colour (required for the
         * clients that strip this block), and inline styles beat a class
         * selector — so these have to be blanket rules with !important, applied
         * to descendants too. Targeting only the wrapper leaves headings and
         * <strong> labels at their inline #0f172a, i.e. dark text on a dark card.
         */
        @media (prefers-color-scheme: dark) {
            .eml-bg { background-color:#0b1120 !important; }

            .eml-card { background-color:#131c2e !important; border-color:#22304a !important; }

            .eml-body,
            .eml-body td, .eml-body th,
            .eml-body p, .eml-body h1, .eml-body span, .eml-body strong {
                color:#e2e8f0 !important;
            }

            .eml-body .eml-muted, .eml-body .eml-muted * { color:#94a3b8 !important; }

            .eml-panel, .eml-panel td { background-color:#0f1930 !important; border-color:#22304a !important; }

            .eml-body th { border-bottom-color:#22304a !important; }
            .eml-body td { border-bottom-color:#1b2740 !important; }
            .eml-rule td { border-top-color:#22304a !important; }

            /*
             * Brand accent stays red. Scoped under .eml-body so it out-specifies
             * the blanket `.eml-body td` rule above, which would otherwise win
             * and turn the order total and hotline plain white.
             */
            .eml-body .eml-accent,
            .eml-body .eml-accent * { color:#f87171 !important; }

            .eml-body .eml-btn a { color:#ffffff !important; }
        }
    </style>
</head>
<body class="eml-bg" style="margin:0; padding:0; width:100%; background-color:#f1f5f9; -webkit-font-smoothing:antialiased;">

    {{-- Inbox preview line. The trailing entities stop the client pulling body copy in after it. --}}
    <div style="display:none; font-size:1px; color:#f1f5f9; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">
        {{ $preheader ?? 'A notification from '.$brand['name'] }}
        &#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
    </div>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="eml-bg" style="background-color:#f1f5f9;">
        <tr>
            <td align="center" style="padding:24px 12px;">

                <!--[if mso]>
                <table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" width="600"><tr><td>
                <![endif]-->

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" class="eml-container eml-card"
                       style="width:600px; max-width:600px; background-color:#ffffff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">

                    {{-- Header --}}
                    <tr>
                        <td align="center" class="eml-pad" style="background-color:#0f172a; padding:26px 32px; border-bottom:3px solid #d12127;">
                            <a href="{{ $brand['url'] }}" style="text-decoration:none;">
                                <span style="display:block; font-family:Arial,Helvetica,sans-serif; font-size:24px; font-weight:bold; color:#ffffff; letter-spacing:-0.4px;">
                                    {{ $brand['name'] }}
                                </span>
                                <span style="display:block; margin-top:5px; font-family:Arial,Helvetica,sans-serif; font-size:11px; color:#94a3b8; text-transform:uppercase; letter-spacing:1.5px;">
                                    {{ $brand['tagline'] }}
                                </span>
                            </a>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td class="eml-pad eml-body" style="padding:32px;">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td class="eml-pad appleLinksGrey" align="center" style="background-color:#0b1120; padding:26px 32px; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:20px; color:#94a3b8;">
                            <p style="margin:0 0 6px; font-size:14px; color:#ffffff; font-weight:bold;">
                                Support Hotline:
                                <a href="{{ \App\Support\BrandDetails::hotlineHref() }}" style="color:#f87171; text-decoration:none;">{{ $brand['hotline'] }}</a>
                            </p>
                            <p style="margin:0 0 4px; color:#94a3b8;">{{ $brand['address'] }}</p>
                            <p style="margin:0 0 14px;">
                                <a href="mailto:{{ $brand['email'] }}" style="color:#94a3b8; text-decoration:underline;">{{ $brand['email'] }}</a>
                            </p>

                            <p style="margin:0 0 14px;">
                                <a href="{{ $brand['url'] }}/track" style="color:#cbd5e1; text-decoration:none;">Track Order</a>
                                <span style="color:#475569;">&nbsp;&middot;&nbsp;</span>
                                <a href="{{ $brand['url'] }}/stores" style="color:#cbd5e1; text-decoration:none;">Showrooms</a>
                                <span style="color:#475569;">&nbsp;&middot;&nbsp;</span>
                                <a href="{{ $brand['url'] }}/warranty" style="color:#cbd5e1; text-decoration:none;">Warranty</a>
                                <span style="color:#475569;">&nbsp;&middot;&nbsp;</span>
                                <a href="{{ $brand['url'] }}" style="color:#cbd5e1; text-decoration:none;">Shop</a>
                            </p>

                            <p style="margin:0; font-size:11px; color:#475569;">
                                &copy; {{ date('Y') }} {{ $brand['name'] }}. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>

                <!--[if mso]></td></tr></table><![endif]-->

            </td>
        </tr>
    </table>
</body>
</html>
