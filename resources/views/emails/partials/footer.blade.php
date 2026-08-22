{{--
    Email footer — shared by every message via the master layout.

    Hotline, address and support email all come from Site Settings, so the admin
    can change them once and every outgoing email follows. They used to be
    hardcoded here, which meant editing them in the admin had no effect.
--}}
@php($brand = $brand ?? \App\Support\BrandDetails::all())
<tr>
    <td class="eml-pad appleLinksGrey" align="center"
        style="background-color:#0b1120; padding:26px 32px; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:20px; color:#94a3b8;">

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
