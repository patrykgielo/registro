<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<title>{{ $brandName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;padding:24px 0;">
<tr>
<td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;">
<tr>
<td style="padding:24px;text-align:center;border-bottom:3px solid {{ $brandColor }};">
@if($logoUrl)
<img src="{{ $logoUrl }}" alt="{{ $brandName }}" style="max-height:48px;max-width:220px;">
@else
<span style="font-size:20px;font-weight:bold;color:#111827;">{{ $brandName }}</span>
@endif
</td>
</tr>
<tr>
<td style="padding:32px 24px;color:#111827;font-size:15px;line-height:1.5;">
{!! $bodyHtml !!}
</td>
</tr>
<tr>
<td style="padding:16px 24px;background-color:#f9fafb;color:#6b7280;font-size:12px;text-align:center;">
<p style="margin:0 0 4px;">{{ $brandName }}</p>
@if($contact['address_line'] || $contact['city'])
<p style="margin:0 0 4px;">{{ trim(implode(', ', array_filter([$contact['address_line'], trim($contact['postal_code'].' '.$contact['city'])]))) }}</p>
@endif
@if($contact['phone'] || $contact['email'])
<p style="margin:0;">{{ trim(implode(' · ', array_filter([$contact['phone'], $contact['email']]))) }}</p>
@endif
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
