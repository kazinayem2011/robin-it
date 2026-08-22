{{--
    Email header — shared by every message via the master layout.

    Everything here comes from Site Settings (see App\Support\BrandDetails), so
    changing the store name, tagline or logo in the admin updates all outgoing
    email without touching a template.

    The background is light on purpose: the logo artwork is dark, so it would be
    unreadable on the navy used elsewhere in the design. The red rule underneath
    keeps the brand accent.

    Images are blocked by default in many clients, so the logo carries alt text
    that reads as the store name, and the URL is absolute — a relative path has
    no page context to resolve against in an inbox.
--}}
@php
    // Kept as one block: Blade mis-compiles a single-line @php(...) directly
    // followed by a multi-line @php block — it opens <?php( and never closes it,
    // so the second block is swallowed and its variables never get defined.
    $brand = $brand ?? \App\Support\BrandDetails::all();

    // Prefer embedding the file in the message (cid:) over linking to it: a URL
    // only resolves if the site is publicly reachable, which it is not in local
    // development. $message is absent when the view is rendered for preview.
    $localLogo = \App\Support\BrandDetails::localLogoPath();
    $logoSrc = (isset($message) && $localLogo)
        ? $message->embed($localLogo)
        : ($brand['logo'] ?? null);
@endphp
<tr>
    <td align="center" class="eml-pad eml-header"
        style="background-color:#ffffff; padding:24px 32px; border-bottom:3px solid #d12127;">
        <a href="{{ $brand['url'] }}" style="text-decoration:none; display:inline-block;">
            @if (! empty($logoSrc))
                <img src="{{ $logoSrc }}"
                     alt="{{ $brand['name'] }}"
                     width="180"
                     style="display:block; width:180px; max-width:180px; height:auto; border:0; outline:none; text-decoration:none;">
            @else
                {{-- No logo configured: fall back to a styled wordmark, and show
                     the tagline underneath. Logo artwork usually already
                     contains it, so repeating it there reads as a duplicate. --}}
                <span style="display:block; font-family:Arial,Helvetica,sans-serif; font-size:24px; font-weight:bold; color:#0f172a; letter-spacing:-0.4px;">
                    {{ $brand['name'] }}
                </span>
                <span class="eml-tagline"
                      style="display:block; margin-top:8px; font-family:Arial,Helvetica,sans-serif; font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:1.5px;">
                    {{ $brand['tagline'] }}
                </span>
            @endif
        </a>
    </td>
</tr>
