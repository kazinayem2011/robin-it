@extends('emails.layouts.master', ['title' => 'Welcome to Robin IT — The Store of Technology'])

@section('content')
    <h2 class="email-heading">Welcome to the Robin IT Family!</h2>
    <p class="email-lead">
        Hi <strong>{{ $user->name }}</strong>,<br>
        Thank you for joining Bangladesh's leading authentic tech hardware platform. Your Robin IT customer account is ready!
    </p>

    <div class="email-info-box">
        <div class="email-info-box-title">Account Benefits</div>
        <p class="email-info-box-text">
            &bull; <strong>1-Click Interactive PC Builder:</strong> Save and share custom rig configurations.<br>
            &bull; <strong>Real-time Order Tracking:</strong> Track deliveries live across all 64 districts.<br>
            &bull; <strong>Exclusive Member Deals:</strong> Early access to flash sales and promo vouchers.<br>
            &bull; <strong>Official Brand Warranties:</strong> Hassle-free warranty tracking and service support.
        </p>
    </div>

    <!-- CTA Button -->
    <div class="email-button-wrap">
        <a href="{{ url('/shop') }}" class="email-button" target="_blank">
            Explore Genuine Tech Catalog &rarr;
        </a>
    </div>

    <p style="font-size: 13px; color: #64748b; line-height: 1.5; text-align: center;">
        Need advice building your dream PC? Call our hardware experts at 
        <strong style="color: #0f172a;">{{ \App\Models\SiteSetting::get('site_hotline', '09600-ROBIN-IT') }}</strong>.
    </p>
@endsection
