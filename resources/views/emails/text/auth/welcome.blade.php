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
@if (! empty($password))

YOUR SIGN-IN DETAILS
@if ($user->phone)
Mobile: {{ $user->phone }}
@endif
@if ($user->email)
Email: {{ $user->email }}
@endif
Password: {{ $password }}

Sign in with your mobile number or email and this password.
You can change it from your profile.
@endif

Start shopping: {{ $brand['url'] }}/shop

Need help choosing parts? Call our team on {{ $brand['hotline'] }}.

--
{{ $brand['name'] }}
{{ $brand['address'] }}
{{ $brand['email'] }}
