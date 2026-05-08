@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (isset($logoUrl) && $logoUrl)
<img src="{{ $logoUrl }}" alt="{{ isset($brandName) ? $brandName : config('app.name') }}" style="height: 40px; max-width: 200px; object-fit: contain;">
@elseif (trim($slot) === 'Laravel')
<img src="https://laravel.com/img/notification-logo-v2.1.png" class="logo" alt="Laravel Logo">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
