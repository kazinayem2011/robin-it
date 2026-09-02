@php($brand = \App\Support\BrandDetails::all())
{{ $brand['name'] }} — {{ $brand['tagline'] }}
==============================================

RESET YOUR PASSWORD

Hi {{ $user->name }},

Someone asked to reset the password on this account. Choose a new one here:

{{ $url }}

The link expires in {{ $expiresInMinutes }} minutes. If this was not you, ignore
this message — the password stays as it is until someone opens that link.

--
{{ $brand['name'] }}
{{ $brand['address'] }}
{{ $brand['email'] }}
