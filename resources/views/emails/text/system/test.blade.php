@php($brand = \App\Support\BrandDetails::all())
{{ $brand['name'] }}
==============================

YOUR EMAIL SETTINGS WORK

This test was sent using the SMTP settings saved in the admin. If you can read
this, order confirmations and status updates will reach your customers too.

Sent: {{ now()->format('d M Y, g:i A') }}
Host: {{ config('mail.mailers.smtp.host') }}:{{ config('mail.mailers.smtp.port') }}
From: {{ config('mail.from.address') }}
