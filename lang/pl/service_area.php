<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Service Area Language Lines (Polish)
    |--------------------------------------------------------------------------
    |
    | Translations for service area validation messages and waitlist forms
    |
    */

    'validation' => [
        'not_available' => 'Przepraszamy, obecnie nie obsługujemy tej lokalizacji.',
        'outside_area' => 'Ta lokalizacja znajduje się :distance km od najbliższego obszaru obsługi (:city).',
        'checking' => 'Sprawdzanie dostępności obszaru obsługi...',
        'success' => 'Świetnie! Obsługujemy Twoją lokalizację',
        'success_detail' => 'Możesz kontynuować rezerwację',
    ],

    'waitlist' => [
        'title' => 'Powiadom mnie, gdy będziecie dostępni',
        'already_available' => 'Ta lokalizacja jest już w naszym obszarze obsługi!',
        'success' => 'Dziękujemy! Powiadomimy Cię, gdy rozszerzymy naszą działalność do Twojej okolicy.',
        'duplicate' => 'Ten adres email jest już na liście oczekujących dla tej lokalizacji.',
        'error' => 'Wystąpił błąd podczas dodawania do listy oczekujących. Spróbuj ponownie.',
        'validation_error' => 'Nie udało się sprawdzić obszaru obsługi. Spróbuj ponownie.',
    ],

    'form' => [
        'email' => 'Twój adres email',
        'email_placeholder' => 'jan.kowalski@example.com',
        'name' => 'Imię i nazwisko',
        'name_placeholder' => 'Jan Kowalski',
        'phone' => 'Telefon',
        'phone_placeholder' => '+48 123 456 789',
        'submit' => 'Powiadom mnie',
        'submitting' => 'Wysyłanie...',
        'optional' => '(opcjonalnie)',
        'required' => '*',
    ],

    'alert' => [
        'location_unavailable' => 'Przepraszamy, nie obsługujemy jeszcze tej lokalizacji. Zapisz się na listę oczekujących, aby otrzymać powiadomienie, gdy będziemy dostępni w Twojej okolicy.',
        'wait_for_validation' => 'Proszę poczekać na sprawdzenie dostępności obszaru obsługi.',
    ],

    'admin' => [
        'resource_label' => 'Obszar obsługi',
        'resource_plural' => 'Obszary obsługi',
        'waitlist_label' => 'Wpis na liście oczekujących',
        'waitlist_plural' => 'Lista oczekujących',

        'map' => [
            'search_label' => 'Wyszukaj adres lub miejsce',
            'search_hint' => 'Zacznij wpisywać nazwę miejsca lub adres, aby wyświetlić podpowiedzi',
            'radius_label' => 'Promień obszaru obsługi',
            'radius_hint' => 'Wprowadź promień w kilometrach (1-200 km) i kliknij "Zaktualizuj" lub naciśnij Enter',
            'update_radius' => 'Zaktualizuj zasięg',
            'latitude' => 'Szerokość geograficzna',
            'longitude' => 'Długość geograficzna',
        ],
    ],
];
