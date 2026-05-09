@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
@php
    // Use tenant brand color for 'primary' buttons when available
    $buttonBgColor = null;
    if ($color === 'primary' && isset($brandColor) && $brandColor) {
        $buttonBgColor = $brandColor;
    }
@endphp
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
@if ($buttonBgColor)
<a href="{{ $url }}"
   class="button button-{{ $color }}"
   style="background-color: {{ $buttonBgColor }}; color: #ffffff; border-radius: 4px; padding: 12px 24px; display: inline-block; text-decoration: none; font-weight: 600;"
   target="_blank" rel="noopener">{!! $slot !!}</a>
@else
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener">{!! $slot !!}</a>
@endif
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
