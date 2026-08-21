<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>{{ $title ?? 'Robin IT Official Notification' }}</title>
    <style>
        /* Base Email Reset */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; }
        
        .email-wrapper { width: 100%; background-color: #f1f5f9; padding: 32px 12px; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05); }
        
        /* Brand Header */
        .email-header { background-color: #0f172a; padding: 28px 32px; text-align: center; border-bottom: 3px solid #ea484f; }
        .brand-logo-text { color: #ffffff; font-size: 24px; font-weight: 900; letter-spacing: -0.5px; text-decoration: none; display: inline-block; }
        .brand-logo-accent { color: #ea484f; }
        .brand-tagline { color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 4px; display: block; }
        
        /* Email Body */
        .email-body { padding: 32px; }
        .email-heading { font-size: 20px; font-weight: 800; color: #0f172a; margin-top: 0; margin-bottom: 12px; line-height: 1.3; }
        .email-lead { font-size: 15px; color: #475569; line-height: 1.6; margin-bottom: 24px; }
        
        /* Items & Summary Table */
        .email-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .email-table th { background-color: #f8fafc; color: #475569; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 10px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .email-table td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; font-size: 14px; color: #334155; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-primary { color: #ea484f; font-weight: 700; }
        
        /* CTA Button */
        .email-button-wrap { text-align: center; margin: 32px 0 20px; }
        .email-button { display: inline-block; background-color: #ea484f; color: #ffffff !important; font-size: 14px; font-weight: 700; padding: 14px 28px; border-radius: 6px; text-decoration: none; letter-spacing: 0.3px; }
        
        /* Info Box */
        .email-info-box { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px 20px; margin: 24px 0; }
        .email-info-box-title { font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .email-info-box-text { font-size: 13px; color: #64748b; margin: 0; line-height: 1.5; }
        
        /* Brand Footer */
        .email-footer { background-color: #0b1120; padding: 28px 32px; text-align: center; color: #64748b; font-size: 12px; line-height: 1.6; }
        .footer-hotline { color: #ffffff; font-weight: 700; font-size: 14px; margin-bottom: 8px; }
        .footer-hotline a { color: #ea484f; text-decoration: none; }
        .footer-links { margin: 12px 0; }
        .footer-links a { color: #94a3b8; text-decoration: none; margin: 0 8px; }
        .footer-links a:hover { color: #ffffff; }
        .footer-copy { margin-top: 16px; color: #475569; font-size: 11px; }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">
            <!-- 1. Common Brand Header -->
            <div class="email-header">
                <a href="{{ url('/') }}" class="brand-logo-text" target="_blank">
                    ROBIN <span class="brand-logo-accent">IT</span>
                </a>
                <span class="brand-tagline">The Store of Technology</span>
            </div>

            <!-- 2. Dynamic Content Body -->
            <div class="email-body">
                @yield('content')
            </div>

            <!-- 3. Common Brand Footer -->
            <div class="email-footer">
                <div class="footer-hotline">
                    Official Support Hotline: <a href="tel:{{ \App\Models\SiteSetting::get('site_hotline', '09600-ROBIN-IT') }}">{{ \App\Models\SiteSetting::get('site_hotline', '09600-ROBIN-IT') }}</a>
                </div>
                <p>
                    Showroom: Shop #301-304, Level 3, IDB Bhaban, Agargaon, Dhaka - 1207<br>
                    Official Warranty Claim Desk &amp; Instant PC Assembly
                </p>
                <div class="footer-links">
                    <a href="{{ url('/track-order') }}">Track Order</a> &bull;
                    <a href="{{ url('/stores') }}">Showrooms</a> &bull;
                    <a href="{{ url('/support') }}">Warranty Policy</a> &bull;
                    <a href="{{ url('/') }}">Storefront</a>
                </div>
                <div class="footer-copy">
                    &copy; {{ date('Y') }} Robins Computer. All Rights Reserved. Genuine Hardware Certified.
                </div>
            </div>
        </div>
    </div>
</body>
</html>
