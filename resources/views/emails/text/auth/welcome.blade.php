@php($brand = \App\Support\BrandDetails::all())
{{ $brand['name'] }} — {{ $brand['tagline'] }}
==============================================

WELCOME TO {{ strtoupper($brand['name']) }}

Hi {{ $user->name }},

Your account is ready. Here's what you can do with it:

- Build a PC with live compatibility checks, then save and share it
- Track deliveries from packing to your door
- Raise and follow warranty (RMA) claims online
- Save addresses for faster checkout

Start shopping: {{ $brand['url'] }}/shop

Need help choosing parts? Call our team on {{ $brand['hotline'] }}.

--
{{ $brand['name'] }}
{{ $brand['address'] }}
{{ $brand['email'] }}
