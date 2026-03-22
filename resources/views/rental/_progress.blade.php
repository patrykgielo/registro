@props(['current' => 1])

@php
$steps = [
    1 => 'Termin i ilość',
    2 => 'Dane kontaktowe',
    3 => 'Podsumowanie',
];
@endphp

<nav aria-label="Postęp rezerwacji" class="mb-8">
    <ol class="flex items-center gap-2">
        @foreach($steps as $number => $label)
            <li class="flex items-center gap-2 {{ !$loop->last ? 'flex-1' : '' }}">
                <div @class([
                    'flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold shrink-0',
                    'bg-brand text-text-inverse' => $number === $current,
                    'bg-success text-text-inverse' => $number < $current,
                    'bg-surface-sunken text-text-muted' => $number > $current,
                ])>
                    @if($number < $current)
                        <x-heroicon-m-check class="h-4 w-4" />
                    @else
                        {{ $number }}
                    @endif
                </div>
                <span @class([
                    'text-sm hidden sm:inline',
                    'font-medium text-text-primary' => $number === $current,
                    'text-text-muted' => $number !== $current,
                ])>{{ $label }}</span>

                @if(!$loop->last)
                    <div @class([
                        'flex-1 h-px mx-2',
                        'bg-success' => $number < $current,
                        'bg-border' => $number >= $current,
                    ])></div>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
