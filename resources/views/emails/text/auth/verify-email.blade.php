@php($brand = \App\Support\BrandDetails::all())
{{ $brand['name'] }} — {{ $brand['tagline'] }}
==============================================

VERIFY YOUR EMAIL ADDRESS

Hi {{ $user->name }},

Confirm this address and we can send your order confirmations, delivery
updates and warranty replies straight to it.

Verify your email address:
{{ $url }}

The link expires in {{ $expiresInMinutes }} minutes. If you did not create an
account, nothing happens — you can ignore this message.

--
{{ $brand['name'] }}
{{ $brand['address'] }}
{{ $brand['email'] }}
