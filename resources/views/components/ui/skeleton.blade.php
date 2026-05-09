@props([
    'lines' => 1,
    'circle' => false,
])

@if($circle)
    <div {{ $attributes->class(['animate-pulse rounded-full bg-surface-sunken h-10 w-10']) }}></div>
@else
    <div {{ $attributes->class(['space-y-3']) }}>
        @for($i = 0; $i < $lines; $i++)
            <div @class([
                'animate-pulse rounded bg-surface-sunken h-4',
                'w-full' => $i < $lines - 1,
                'w-3/4' => $i === $lines - 1 && $lines > 1,
            ])></div>
        @endfor
    </div>
@endif
