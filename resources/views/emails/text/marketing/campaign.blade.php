{{ strtoupper($campaign->subject ?: $campaign->title) }}

{{ strip_tags($body) }}

--
You are receiving this because you shop with {{ $brand['name'] }} or joined our
mailing list. To stop these, open the link below — one click, no form:

{{ $unsubscribeUrl }}
