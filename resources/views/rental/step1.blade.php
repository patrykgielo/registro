@extends('layouts.app')

@section('content')
<x-layout.section>
    <div class="max-w-2xl mx-auto"
         x-data="{
            startDate: '{{ old('start_date', $step1['start_date'] ?? '') }}',
            endDate: '{{ old('end_date', $step1['end_date'] ?? '') }}',
            quantity: {{ old('quantity', $step1['quantity'] ?? 1) }},

            pricePerDay: {{ (float) $service->price_per_day }},
            pricePerDayLong: {{ (float) ($service->price_per_day_long ?? 0) }},
            thresholdDays: {{ (int) ($service->price_threshold_days ?? 0) }},
            pricePerWeek: {{ (float) ($service->price_per_week ?? 0) }},
            depositAmount: {{ (float) ($service->deposit_amount ?? 0) }},

            get days() {
                if (!this.startDate || !this.endDate) return 0;
                const start = new Date(this.startDate);
                const end = new Date(this.endDate);
                if (end < start) return 0;
                return Math.round((end - start) / (1000 * 60 * 60 * 24)) + 1;
            },

            get unitPrice() {
                if (this.pricePerDayLong > 0 && this.thresholdDays > 0 && this.days >= this.thresholdDays) {
                    return this.pricePerDayLong;
                }
                return this.pricePerDay;
            },

            get totalPrice() {
                if (this.days <= 0 || this.quantity <= 0) return 0;
                // Weekly rate check
                if (this.pricePerWeek > 0 && this.days >= 7) {
                    const weeklyPerDay = this.pricePerWeek / 7;
                    if (weeklyPerDay < this.unitPrice) {
                        const weeks = Math.floor(this.days / 7);
                        const remaining = this.days % 7;
                        return (weeks * this.pricePerWeek + remaining * this.unitPrice) * this.quantity;
                    }
                }
                return this.unitPrice * this.days * this.quantity;
            },

            get totalWithDeposit() {
                return this.totalPrice + this.depositAmount;
            },

            get isDiscounted() {
                return this.pricePerDayLong > 0 && this.thresholdDays > 0 && this.days >= this.thresholdDays;
            },

            availableQty: null,
            checking: false,

            async checkAvailability() {
                if (!this.startDate || !this.endDate) {
                    this.availableQty = null;
                    return;
                }
                this.checking = true;
                try {
                    const res = await fetch(`{{ route('rental.availability', $service) }}?start_date=${this.startDate}&end_date=${this.endDate}`);
                    if (res.ok) {
                        const data = await res.json();
                        this.availableQty = data.available_quantity;
                    }
                } catch (e) {
                    this.availableQty = null;
                } finally {
                    this.checking = false;
                }
            },

            formatPrice(val) {
                return val.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            },

            init() {
                this.$watch('startDate', () => this.checkAvailability());
                this.$watch('endDate', () => this.checkAvailability());
                if (this.startDate && this.endDate) this.checkAvailability();
            }
         }">

        @include('rental._progress', ['current' => 1])

        @if(session('error'))
            <div class="mb-4 p-4 rounded-xl bg-danger/10 border border-danger/20 text-sm text-danger">
                {{ session('error') }}
            </div>
        @endif

        <x-ui.card>
            <div class="flex items-center gap-4 mb-6 pb-6 border-b border-border">
                @if($service->featured_image)
                    <x-media.image :src="$service->featured_image" :alt="$service->name" aspect="1/1" rounded="lg" class="w-16 h-16 shrink-0" />
                @endif
                <div>
                    <h1 class="text-xl font-bold text-text-primary">{{ $service->name }}</h1>
                    @if($service->brand)
                        <p class="text-sm text-text-muted">{{ $service->brand }}</p>
                    @endif
                </div>
            </div>

            <form action="{{ route('rental.step1.store', $service) }}" method="POST" class="space-y-6">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.input
                        type="date"
                        name="start_date"
                        label="Data rozpoczęcia"
                        x-model="startDate"
                        :error="$errors->first('start_date')"
                        min="{{ now()->format('Y-m-d') }}"
                        required
                    />

                    <x-ui.input
                        type="date"
                        name="end_date"
                        label="Data zakończenia"
                        x-model="endDate"
                        :error="$errors->first('end_date')"
                        :min="now()->format('Y-m-d')"
                        required
                    />
                </div>

                <x-ui.input
                    type="number"
                    name="quantity"
                    label="Ilość (szt.)"
                    x-model.number="quantity"
                    :error="$errors->first('quantity')"
                    min="1"
                    :max="$service->quantity_total"
                    required
                />

                {{-- Live availability indicator --}}
                <div x-show="availableQty !== null" x-transition class="text-sm">
                    <template x-if="availableQty > 0">
                        <p class="text-success flex items-center gap-1.5">
                            <x-heroicon-m-check-circle class="h-4 w-4 shrink-0" />
                            <span>Dostępnych: <span x-text="availableQty"></span> szt. w wybranym terminie</span>
                        </p>
                    </template>
                    <template x-if="availableQty === 0">
                        <p class="text-danger flex items-center gap-1.5">
                            <x-heroicon-m-x-circle class="h-4 w-4 shrink-0" />
                            <span>Brak dostępności w wybranym terminie</span>
                        </p>
                    </template>
                </div>
                <div x-show="checking" class="text-sm text-text-muted flex items-center gap-1.5">
                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span>Sprawdzam dostępność…</span>
                </div>

                {{-- Live Price Calculator --}}
                @if($service->price_per_day)
                    <div class="rounded-xl border border-border overflow-hidden">
                        {{-- Static rates --}}
                        <div class="bg-surface-sunken p-4">
                            <h3 class="text-sm font-semibold text-text-muted mb-2">Cennik</h3>
                            <div class="space-y-1 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-text-secondary">Stawka dzienna</span>
                                    <span class="font-medium text-text-primary">{{ number_format($service->price_per_day, 2, ',', ' ') }} zł</span>
                                </div>
                                @if($service->price_per_day_long && $service->price_threshold_days)
                                    <div class="flex justify-between text-success">
                                        <span>Od {{ $service->price_threshold_days }}+ dni</span>
                                        <span class="font-medium">{{ number_format($service->price_per_day_long, 2, ',', ' ') }} zł/dzień</span>
                                    </div>
                                @endif
                                @if($service->price_per_week)
                                    <div class="flex justify-between">
                                        <span class="text-text-secondary">Stawka tygodniowa</span>
                                        <span class="font-medium text-text-primary">{{ number_format($service->price_per_week, 2, ',', ' ') }} zł</span>
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Dynamic calculation --}}
                        <div class="p-4 space-y-2" x-show="days > 0" x-transition>
                            <div class="flex justify-between text-sm">
                                <span class="text-text-secondary">
                                    <span x-text="days"></span> <span x-text="days === 1 ? 'dzień' : 'dni'"></span>
                                    &times; <span x-text="formatPrice(unitPrice)"></span> zł
                                    <template x-if="quantity > 1"> &times; <span x-text="quantity"></span> szt.</template>
                                </span>
                            </div>

                            <template x-if="isDiscounted">
                                <div class="flex items-center gap-2 text-sm text-success">
                                    <x-heroicon-m-tag class="h-4 w-4 shrink-0" />
                                    <span>Zastosowano stawkę obniżoną!</span>
                                </div>
                            </template>

                            <x-ui.separator />

                            <div class="flex justify-between text-base">
                                <span class="font-semibold text-text-primary">Szacowany koszt</span>
                                <span class="font-bold text-text-primary" x-text="formatPrice(totalPrice) + ' zł'"></span>
                            </div>

                            @if($service->deposit_amount)
                                <div class="flex justify-between text-sm">
                                    <span class="text-text-secondary">+ kaucja zwrotna</span>
                                    <span class="font-medium text-text-primary">{{ number_format($service->deposit_amount, 2, ',', ' ') }} zł</span>
                                </div>
                                <div class="flex justify-between text-base pt-2 border-t border-border">
                                    <span class="font-semibold text-text-primary">Łącznie przy odbiorze</span>
                                    <span class="font-bold text-brand" x-text="formatPrice(totalWithDeposit) + ' zł'"></span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="flex items-center justify-between pt-4">
                    <x-ui.button variant="ghost" href="{{ route('service.show', $service) }}" icon="arrow-left">
                        Wróć
                    </x-ui.button>
                    <x-ui.button type="submit" icon-right="arrow-right">
                        Dalej
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layout.section>
@endsection
