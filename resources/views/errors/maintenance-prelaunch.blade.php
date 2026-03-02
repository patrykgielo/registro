@php
// Get Settings for defaults and contact info
$settings = app(\App\Support\Settings\SettingsManager::class);
$prelaunchDefaults = $settings->group('prelaunch');
$contact = $settings->group('contact');

// Build config with fallback chain: $config → Settings → Hardcoded
$pageTitle = $config['page_title'] ?? $prelaunchDefaults['page_title'] ?? 'Wkrótce startujemy - Registro';
$mainHeading = $config['main_heading'] ?? $prelaunchDefaults['heading'] ?? 'Wkrótce Ruszamy!';
$tagline = $config['tagline'] ?? $prelaunchDefaults['tagline'] ?? 'Registro polega na tym, że to my przyjeżdżamy do Ciebie, a nie Ty do Nas!';
$launchDateLabel = $config['launch_date_label'] ?? $prelaunchDefaults['date_label'] ?? 'Data startu';
$launchDate = isset($config['launch_date']) ? \Carbon\Carbon::parse($config['launch_date'])->format('d.m.Y') : '10.01.2026';
$descriptionPart1 = $config['description_part1'] ?? $prelaunchDefaults['description_1'] ?? 'Wprowadzamy autorski system rezerwacji mobilnych usług mycia pojazdów oraz detailingu.';
$descriptionPart2 = $config['description_part2'] ?? $prelaunchDefaults['description_2'] ?? 'Świadczymy usługi we wskazanej przez Ciebie lokalizacji.';
$contactHeading = $config['contact_heading'] ?? $prelaunchDefaults['contact_heading'] ?? 'Masz pytania?';
$copyrightText = $config['copyright_text'] ?? $prelaunchDefaults['copyright_text'] ?? 'Registro. Wszelkie prawa zastrzeżone.';
$htmlLang = $config['html_lang'] ?? 'pl';

// Images: FileUpload storage path or fallback to defaults
$backgroundImage = !empty($config['background_image']) ? Storage::url($config['background_image']) : '/images/maintenance-background.png';
$logoPath = $contact['logo_path'] ?? '/images/logo.svg';
$logoAlt = $contact['logo_alt'] ?? 'Registro - Mobilne Myjnie Parowe';

// Contact info from Settings (single source of truth)
$contactEmail = $contact['email'] ?? 'kontakt@registro.local';
$contactPhone = $contact['phone'] ?? '+48 123 456 789';
@endphp
<!DOCTYPE html>
<html lang="{{ $htmlLang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <!-- Full-Screen Background -->
    <div class="relative min-h-screen bg-cover bg-center bg-fixed"
         style="background-image: url('{{ $backgroundImage }}'); background-color: #0f1419;">

        <!-- Content -->
        <div class="relative z-10 min-h-screen flex flex-col items-center justify-center p-4 sm:p-6 pb-6">

            <!-- Logo (ABOVE card) -->
            <div class="flex justify-center mb-8 sm:mb-12  max-w-lg animate-fade-in-up">
                <img src="{{ $logoPath }}"
                     alt="{{ $logoAlt }}"
                     class="h-auto drop-shadow-2xl">
            </div>

            <!-- Main Content Card -->
            <div class="w-full max-w-xl md:max-w-2xl bg-gray-900/60 backdrop-blur-lg rounded-2xl shadow-2xl border border-cyan-500/30 p-6 sm:p-8 md:p-10 animate-fade-in-up">

                <!-- Main Heading -->
                <h1 class="text-2xl sm:text-3xl md:text-4xl lg:text-5xl font-bold text-white text-center mb-12">{{ $mainHeading }}</h1>

                <!-- Subheading -->
                <p class="text-base sm:text-lg md:text-xl text-gray-200 text-center mb-12 leading-relaxed">
                    {{ $tagline }}
                </p>

                <!-- Launch Date -->
                <div class="text-center mb-8 pb-8 border-b border-cyan-500/20">
                    <p class="text-xl text-cyan-400 tracking-wide uppercase mb-6 d-block">{{ $launchDateLabel }}</p>
                    <p class="text-3xl sm:text-6xl md:text-5xl font-bold text-white">{{ $launchDate }}</p>
                </div>

                <!-- Main Message -->
                <div class="text-center mb-8">
                    <p class="text-base text-gray-300 leading-relaxed text-bold mb-8">
                        {{ $descriptionPart1 }}
                    </p>
                    <p class="text-base text-gray-300 leading-relaxed text-bold">
                        {{ $descriptionPart2 }}
                    </p>
                </div>

                <!-- Contact Info -->
                <div class="bg-gray-800/40 backdrop-blur-sm border border-cyan-500/20 rounded-xl p-6 text-center">
                    <h3 class="text-2xl font-bold text-white mb-4">{{ $contactHeading }}</h3>

                    <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                        <a href="mailto:{{ $contactEmail }}"
                           class="flex items-center px-5 py-3 bg-cyan-600/20 hover:bg-cyan-500/30 border border-cyan-400/30 rounded-lg text-white transition-all duration-200 shadow-lg hover:shadow-cyan-500/20">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            {{ $contactEmail }}
                        </a>

                        <a href="tel:{{ str_replace(' ', '', $contactPhone) }}"
                           class="flex items-center px-5 py-3 bg-cyan-600/20 hover:bg-cyan-500/30 border border-cyan-400/30 rounded-lg text-white transition-all duration-200 shadow-lg hover:shadow-cyan-500/20">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                            </svg>
                            {{ $contactPhone }}
                        </a>
                    </div>
                </div>

            </div>

        </div>

        <!-- Footer -->
        <div class="p-6 text-center">
            <p class="text-sm text-gray-400">
                &copy; <script>document.write(new Date().getFullYear())</script> {{ $copyrightText }}
            </p>
        </div>

    </div>
</body>
</html>
