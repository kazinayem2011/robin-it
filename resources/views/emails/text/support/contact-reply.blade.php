@php($brand = \App\Support\BrandDetails::all())
Re: {{ $contactMessage->subject }}

Hi {{ $contactMessage->name }},

{{ $reply->body }}

-- {{ $reply->author_name }}, {{ $brand['name'] }}

---
You wrote on {{ $contactMessage->created_at->format('d M Y') }}:
{{ \Illuminate\Support\Str::limit($contactMessage->message, 600) }}

Reply to this email if you need anything else.
