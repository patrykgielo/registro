<x-ios.auth-card
    title="Gratulacje!"
    subtitle="{{ $organization->name }} jest gotowe"
>
    <div class="text-center space-y-6">
        {{-- Success Icon --}}
        <div class="flex justify-center">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center animate-bounce-slow">
                <x-heroicon-o-check-circle class="w-12 h-12 text-green-500" />
            </div>
        </div>

        {{-- Info --}}
        <div class="space-y-2">
            <p class="text-gray-700">
                Twoja firma <strong>{{ $organization->name }}</strong> została utworzona.
            </p>
            <p class="text-sm text-gray-500">
                Okres próbny: <strong>14 dni</strong> (do {{ $organization->trial_ends_at->format('d.m.Y') }})
            </p>
        </div>

        {{-- Subdomain preview --}}
        <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
            <p class="text-xs text-gray-500 mb-1">Adres Twojego panelu administracyjnego</p>
            <p class="font-mono text-sm text-primary-600 font-semibold break-all">{{ $adminUrl }}</p>
        </div>

        {{-- CTA Button --}}
        <a href="{{ $adminUrl }}"
           id="admin-link"
           class="block w-full bg-primary-500 text-white font-semibold py-4 rounded-lg shadow-lg hover:shadow-xl hover:scale-[1.02] active:scale-[0.98] transition-all duration-300 focus:outline-none focus:ring-4 focus:ring-primary/30">
            <span class="flex items-center justify-center gap-2">
                Otwórz panel administracyjny
                <x-heroicon-m-arrow-right class="w-5 h-5" />
            </span>
        </a>

        {{-- Auto-redirect countdown --}}
        <p class="text-xs text-gray-400" id="countdown">
            Automatyczne przekierowanie za <span id="seconds">5</span> sekund...
        </p>
    </div>
</x-ios.auth-card>

<style>
    @keyframes bounce-slow {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }
    .animate-bounce-slow {
        animation: bounce-slow 2s ease-in-out infinite;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let seconds = 5;
    const el = document.getElementById('seconds');
    const link = document.getElementById('admin-link');

    const timer = setInterval(function() {
        seconds--;
        el.textContent = seconds;
        if (seconds <= 0) {
            clearInterval(timer);
            window.location.href = link.href;
        }
    }, 1000);
});
</script>
