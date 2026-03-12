<x-ios.auth-card
    title="Rozpocznij z Registro"
    subtitle="Krok 1 z 2 — Informacje o firmie"
>
    <form method="POST" action="{{ route('onboarding.step1.store') }}" class="space-y-6">
        @csrf

        {{-- Organization Name --}}
        <x-ios.input
            type="text"
            name="org_name"
            label="Nazwa firmy"
            placeholder="np. Studio Bella"
            icon="building-office"
            :value="old('org_name', $data['org_name'] ?? '')"
            required
            autofocus
            autocomplete="organization"
            id="org_name"
        />

        {{-- Slug (auto-generated, editable) --}}
        <div>
            <x-ios.input
                type="text"
                name="slug"
                label="Adres Twojej strony"
                placeholder="studio-bella"
                icon="globe-alt"
                :value="old('slug', $data['slug'] ?? '')"
                required
                autocomplete="off"
                id="slug"
            />
            <p class="mt-1 text-xs text-gray-500" id="slug-preview">
                Twoja strona: <strong id="slug-url">---</strong>.registro.app
            </p>
            <p class="mt-1 text-xs hidden" id="slug-status"></p>
        </div>

        {{-- Booking Type --}}
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-3">Typ działalności</label>
            @error('booking_type')
                <p class="text-red-500 text-xs mb-2">{{ $message }}</p>
            @enderror

            <div class="space-y-3">
                @php
                    $selectedType = old('booking_type', $data['booking_type'] ?? '');
                @endphp

                {{-- Time Slot --}}
                <label class="flex items-start gap-4 p-4 rounded-lg border-2 cursor-pointer transition-all duration-200
                    {{ $selectedType === 'time_slot' ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300' }}">
                    <input type="radio" name="booking_type" value="time_slot"
                        class="mt-1 w-5 h-5 text-primary-500 focus:ring-primary-500"
                        {{ $selectedType === 'time_slot' ? 'checked' : '' }}>
                    <div>
                        <span class="font-semibold text-gray-900">Rezerwacja terminów</span>
                        <p class="text-sm text-gray-500 mt-0.5">Salon, klinika, warsztat — klienci rezerwują konkretne godziny</p>
                    </div>
                </label>

                {{-- Item Rental --}}
                <label class="flex items-start gap-4 p-4 rounded-lg border-2 cursor-pointer transition-all duration-200
                    {{ $selectedType === 'item_rental' ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300' }}">
                    <input type="radio" name="booking_type" value="item_rental"
                        class="mt-1 w-5 h-5 text-primary-500 focus:ring-primary-500"
                        {{ $selectedType === 'item_rental' ? 'checked' : '' }}>
                    <div>
                        <span class="font-semibold text-gray-900">Wypożyczalnia</span>
                        <p class="text-sm text-gray-500 mt-0.5">Sprzęt, pojazdy, narzędzia — klienci wypożyczają na określony czas</p>
                    </div>
                </label>

                {{-- Both --}}
                <label class="flex items-start gap-4 p-4 rounded-lg border-2 cursor-pointer transition-all duration-200
                    {{ $selectedType === 'both' ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300' }}">
                    <input type="radio" name="booking_type" value="both"
                        class="mt-1 w-5 h-5 text-primary-500 focus:ring-primary-500"
                        {{ $selectedType === 'both' ? 'checked' : '' }}>
                    <div>
                        <span class="font-semibold text-gray-900">Oba modele</span>
                        <p class="text-sm text-gray-500 mt-0.5">Rezerwacje terminów i wypożyczenia w jednym miejscu</p>
                    </div>
                </label>
            </div>
        </div>

        {{-- Submit --}}
        <button type="submit"
                class="w-full bg-primary-500 text-white font-semibold py-4 rounded-lg shadow-lg hover:shadow-xl hover:scale-[1.02] active:scale-[0.98] transition-all duration-300 ios-spring focus:outline-none focus:ring-4 focus:ring-primary/30">
            <span class="flex items-center justify-center gap-2">
                Dalej
                <x-heroicon-m-arrow-right class="w-5 h-5" />
            </span>
        </button>
    </form>

    <x-slot:footer>
        <p class="text-sm text-white/90">
            Masz już konto?
            <a href="{{ route('login') }}"
               class="font-semibold text-white hover:text-white/80 transition-colors underline decoration-2 underline-offset-4">
                Zaloguj się
            </a>
        </p>
    </x-slot:footer>
</x-ios.auth-card>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const nameInput = document.getElementById('org_name');
    const slugInput = document.getElementById('slug');
    const slugUrl = document.getElementById('slug-url');
    const slugStatus = document.getElementById('slug-status');
    let slugManuallyEdited = false;
    let debounceTimer;

    // Auto-generate slug from name
    nameInput.addEventListener('input', function() {
        if (slugManuallyEdited) return;
        const slug = generateSlug(this.value);
        slugInput.value = slug;
        slugUrl.textContent = slug || '---';
        checkSlugAvailability(slug);
    });

    // Track manual slug edits
    slugInput.addEventListener('input', function() {
        slugManuallyEdited = true;
        const slug = this.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
        this.value = slug;
        slugUrl.textContent = slug || '---';
        checkSlugAvailability(slug);
    });

    // Update preview on load
    if (slugInput.value) {
        slugUrl.textContent = slugInput.value;
    }

    function generateSlug(name) {
        return name
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[łŁ]/g, 'l')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/-{2,}/g, '-')
            .replace(/^-|-$/g, '')
            .substring(0, 50);
    }

    function checkSlugAvailability(slug) {
        clearTimeout(debounceTimer);
        if (slug.length < 3) {
            slugStatus.classList.add('hidden');
            return;
        }
        debounceTimer = setTimeout(function() {
            fetch('{{ route("onboarding.check-slug") }}?slug=' + encodeURIComponent(slug))
                .then(r => r.json())
                .then(data => {
                    slugStatus.classList.remove('hidden');
                    if (data.available) {
                        slugStatus.textContent = 'Adres dostępny';
                        slugStatus.className = 'mt-1 text-xs text-green-600';
                    } else {
                        slugStatus.textContent = 'Adres zajęty' + (data.suggestion ? ' — sugestia: ' + data.suggestion : '');
                        slugStatus.className = 'mt-1 text-xs text-red-500';
                    }
                })
                .catch(() => slugStatus.classList.add('hidden'));
        }, 400);
    }

    // Radio button visual feedback
    document.querySelectorAll('input[name="booking_type"]').forEach(radio => {
        radio.addEventListener('change', function() {
            document.querySelectorAll('input[name="booking_type"]').forEach(r => {
                r.closest('label').classList.remove('border-primary-500', 'bg-primary-50');
                r.closest('label').classList.add('border-gray-200');
            });
            this.closest('label').classList.remove('border-gray-200');
            this.closest('label').classList.add('border-primary-500', 'bg-primary-50');
        });
    });
});
</script>
