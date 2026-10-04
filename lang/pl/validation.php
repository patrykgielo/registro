<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Polish counterpart of lang/en/validation.php. Both files must carry the
    | identical key set, including every key of Laravel's own
    | Illuminate/Translation/lang/en/validation.php (see LocalisationParityTest).
    |
    */

    'accepted' => 'Pole :attribute musi zostać zaakceptowane.',
    'accepted_if' => 'Pole :attribute musi zostać zaakceptowane, gdy :other ma wartość :value.',
    'active_url' => 'Pole :attribute musi zawierać prawidłowy adres URL.',
    'after' => 'Pole :attribute musi zawierać datę późniejszą niż :date.',
    'after_or_equal' => 'Pole :attribute musi zawierać datę nie wcześniejszą niż :date.',
    'alpha' => 'Pole :attribute może zawierać tylko litery.',
    'alpha_dash' => 'Pole :attribute może zawierać tylko litery, cyfry, myślniki i podkreślenia.',
    'alpha_num' => 'Pole :attribute może zawierać tylko litery i cyfry.',
    'any_of' => 'Pole :attribute jest nieprawidłowe.',
    'array' => 'Pole :attribute musi być tablicą.',
    'ascii' => 'Pole :attribute może zawierać tylko jednobajtowe znaki alfanumeryczne i symbole.',
    'before' => 'Pole :attribute musi zawierać datę wcześniejszą niż :date.',
    'before_or_equal' => 'Pole :attribute musi zawierać datę nie późniejszą niż :date.',
    'between' => [
        'array' => 'Liczba elementów w polu :attribute musi mieścić się w zakresie od :min do :max.',
        'file' => 'Rozmiar pliku w polu :attribute musi mieścić się w zakresie od :min do :max KB.',
        'numeric' => 'Pole :attribute musi być liczbą od :min do :max.',
        'string' => 'Liczba znaków w polu :attribute musi mieścić się w zakresie od :min do :max.',
    ],
    'boolean' => 'Pole :attribute musi mieć wartość prawda lub fałsz.',
    'can' => 'Pole :attribute zawiera niedozwoloną wartość.',
    'confirmed' => 'Potwierdzenie w polu :attribute nie zgadza się.',
    'contains' => 'W polu :attribute brakuje wymaganej wartości.',
    'current_password' => 'Podane hasło jest nieprawidłowe.',
    'date' => 'Pole :attribute musi zawierać prawidłową datę.',
    'date_equals' => 'Pole :attribute musi zawierać datę równą :date.',
    'date_format' => 'Pole :attribute musi mieć format daty :format.',
    'decimal' => 'Liczba miejsc po przecinku w polu :attribute musi wynosić :decimal.',
    'declined' => 'Pole :attribute musi zostać odrzucone.',
    'declined_if' => 'Pole :attribute musi zostać odrzucone, gdy :other ma wartość :value.',
    'different' => 'Pola :attribute oraz :other muszą się różnić.',
    'digits' => 'Liczba cyfr w polu :attribute musi wynosić :digits.',
    'digits_between' => 'Liczba cyfr w polu :attribute musi mieścić się w zakresie od :min do :max.',
    'dimensions' => 'Obraz w polu :attribute ma nieprawidłowe wymiary.',
    'distinct' => 'Pole :attribute zawiera zduplikowaną wartość.',
    'doesnt_contain' => 'Pole :attribute nie może zawierać żadnej z następujących wartości: :values.',
    'doesnt_end_with' => 'Pole :attribute nie może kończyć się żadną z następujących wartości: :values.',
    'doesnt_start_with' => 'Pole :attribute nie może zaczynać się od żadnej z następujących wartości: :values.',
    'email' => 'Pole :attribute musi zawierać prawidłowy adres email.',
    'encoding' => 'Pole :attribute musi być zakodowane w :encoding.',
    'ends_with' => 'Pole :attribute musi kończyć się jedną z następujących wartości: :values.',
    'enum' => 'Wybrana wartość pola :attribute jest nieprawidłowa.',
    'exists' => 'Wybrana wartość pola :attribute jest nieprawidłowa.',
    'extensions' => 'Plik w polu :attribute musi mieć jedno z następujących rozszerzeń: :values.',
    'file' => 'Pole :attribute musi być plikiem.',
    'filled' => 'Pole :attribute nie może być puste.',
    'gt' => [
        'array' => 'Liczba elementów w polu :attribute musi być większa niż :value.',
        'file' => 'Rozmiar pliku w polu :attribute musi być większy niż :value KB.',
        'numeric' => 'Pole :attribute musi być większe niż :value.',
        'string' => 'Liczba znaków w polu :attribute musi być większa niż :value.',
    ],
    'gte' => [
        'array' => 'Liczba elementów w polu :attribute musi wynosić co najmniej :value.',
        'file' => 'Rozmiar pliku w polu :attribute musi wynosić co najmniej :value KB.',
        'numeric' => 'Pole :attribute musi być nie mniejsze niż :value.',
        'string' => 'Liczba znaków w polu :attribute musi wynosić co najmniej :value.',
    ],
    'hex_color' => 'Pole :attribute musi zawierać prawidłowy kolor w zapisie szesnastkowym.',
    'image' => 'Plik w polu :attribute musi być obrazem.',
    'in' => 'Wybrana wartość pola :attribute jest nieprawidłowa.',
    'in_array' => 'Wartość pola :attribute musi występować w polu :other.',
    'in_array_keys' => 'Pole :attribute musi zawierać co najmniej jeden z następujących kluczy: :values.',
    'integer' => 'Pole :attribute musi być liczbą całkowitą.',
    'ip' => 'Pole :attribute musi zawierać prawidłowy adres IP.',
    'ipv4' => 'Pole :attribute musi zawierać prawidłowy adres IPv4.',
    'ipv6' => 'Pole :attribute musi zawierać prawidłowy adres IPv6.',
    'json' => 'Pole :attribute musi zawierać prawidłowy ciąg JSON.',
    'list' => 'Pole :attribute musi być listą.',
    'lowercase' => 'Pole :attribute musi być zapisane małymi literami.',
    'lt' => [
        'array' => 'Liczba elementów w polu :attribute musi być mniejsza niż :value.',
        'file' => 'Rozmiar pliku w polu :attribute musi być mniejszy niż :value KB.',
        'numeric' => 'Pole :attribute musi być mniejsze niż :value.',
        'string' => 'Liczba znaków w polu :attribute musi być mniejsza niż :value.',
    ],
    'lte' => [
        'array' => 'Liczba elementów w polu :attribute nie może przekraczać :value.',
        'file' => 'Rozmiar pliku w polu :attribute nie może przekraczać :value KB.',
        'numeric' => 'Pole :attribute musi być nie większe niż :value.',
        'string' => 'Liczba znaków w polu :attribute nie może przekraczać :value.',
    ],
    'mac_address' => 'Pole :attribute musi zawierać prawidłowy adres MAC.',
    'max' => [
        'array' => 'Liczba elementów w polu :attribute nie może przekraczać :max.',
        'file' => 'Rozmiar pliku w polu :attribute nie może przekraczać :max KB.',
        'numeric' => 'Pole :attribute nie może być większe niż :max.',
        'string' => 'Liczba znaków w polu :attribute nie może przekraczać :max.',
    ],
    'max_digits' => 'Liczba cyfr w polu :attribute nie może przekraczać :max.',
    'mimes' => 'Plik w polu :attribute musi być typu: :values.',
    'mimetypes' => 'Plik w polu :attribute musi być typu: :values.',
    'min' => [
        'array' => 'Liczba elementów w polu :attribute musi wynosić co najmniej :min.',
        'file' => 'Rozmiar pliku w polu :attribute musi wynosić co najmniej :min KB.',
        'numeric' => 'Pole :attribute musi być nie mniejsze niż :min.',
        'string' => 'Liczba znaków w polu :attribute musi wynosić co najmniej :min.',
    ],
    'min_digits' => 'Liczba cyfr w polu :attribute musi wynosić co najmniej :min.',
    'missing' => 'Pole :attribute musi być nieobecne.',
    'missing_if' => 'Pole :attribute musi być nieobecne, gdy :other ma wartość :value.',
    'missing_unless' => 'Pole :attribute musi być nieobecne, chyba że :other ma wartość :value.',
    'missing_with' => 'Pole :attribute musi być nieobecne, gdy występuje :values.',
    'missing_with_all' => 'Pole :attribute musi być nieobecne, gdy występują :values.',
    'multiple_of' => 'Pole :attribute musi być wielokrotnością :value.',
    'not_in' => 'Wybrana wartość pola :attribute jest nieprawidłowa.',
    'not_regex' => 'Format pola :attribute jest nieprawidłowy.',
    'numeric' => 'Pole :attribute musi być liczbą.',
    'password' => [
        'letters' => 'Pole :attribute musi zawierać co najmniej jedną literę.',
        'mixed' => 'Pole :attribute musi zawierać co najmniej jedną wielką i jedną małą literę.',
        'numbers' => 'Pole :attribute musi zawierać co najmniej jedną cyfrę.',
        'symbols' => 'Pole :attribute musi zawierać co najmniej jeden znak specjalny.',
        'uncompromised' => 'Podana wartość pola :attribute pojawiła się w wycieku danych. Wybierz inną wartość.',
    ],
    'present' => 'Pole :attribute musi być obecne.',
    'present_if' => 'Pole :attribute musi być obecne, gdy :other ma wartość :value.',
    'present_unless' => 'Pole :attribute musi być obecne, chyba że :other ma wartość :value.',
    'present_with' => 'Pole :attribute musi być obecne, gdy występuje :values.',
    'present_with_all' => 'Pole :attribute musi być obecne, gdy występują :values.',
    'prohibited' => 'Pole :attribute jest zabronione.',
    'prohibited_if' => 'Pole :attribute jest zabronione, gdy :other ma wartość :value.',
    'prohibited_if_accepted' => 'Pole :attribute jest zabronione, gdy :other jest zaakceptowane.',
    'prohibited_if_declined' => 'Pole :attribute jest zabronione, gdy :other jest odrzucone.',
    'prohibited_unless' => 'Pole :attribute jest zabronione, chyba że :other znajduje się wśród: :values.',
    'prohibits' => 'Pole :attribute uniemożliwia podanie pola :other.',
    'regex' => 'Format pola :attribute jest nieprawidłowy.',
    'required' => 'Pole :attribute jest wymagane.',
    'required_array_keys' => 'Pole :attribute musi zawierać wpisy dla: :values.',
    'required_if' => 'Pole :attribute jest wymagane, gdy :other ma wartość :value.',
    'required_if_accepted' => 'Pole :attribute jest wymagane, gdy :other jest zaakceptowane.',
    'required_if_declined' => 'Pole :attribute jest wymagane, gdy :other jest odrzucone.',
    'required_unless' => 'Pole :attribute jest wymagane, chyba że :other znajduje się wśród: :values.',
    'required_with' => 'Pole :attribute jest wymagane, gdy występuje :values.',
    'required_with_all' => 'Pole :attribute jest wymagane, gdy występują :values.',
    'required_without' => 'Pole :attribute jest wymagane, gdy nie występuje :values.',
    'required_without_all' => 'Pole :attribute jest wymagane, gdy nie występuje żadne z pól: :values.',
    'same' => 'Pola :attribute oraz :other muszą być takie same.',
    'size' => [
        'array' => 'Liczba elementów w polu :attribute musi wynosić :size.',
        'file' => 'Rozmiar pliku w polu :attribute musi wynosić :size KB.',
        'numeric' => 'Pole :attribute musi mieć wartość :size.',
        'string' => 'Liczba znaków w polu :attribute musi wynosić :size.',
    ],
    'starts_with' => 'Pole :attribute musi zaczynać się od jednej z następujących wartości: :values.',
    'string' => 'Pole :attribute musi być tekstem.',
    'timezone' => 'Pole :attribute musi zawierać prawidłową strefę czasową.',
    'unique' => 'Wartość pola :attribute jest już zajęta.',
    'uploaded' => 'Nie udało się przesłać pliku w polu :attribute.',
    'uppercase' => 'Pole :attribute musi być zapisane wielkimi literami.',
    'url' => 'Pole :attribute musi zawierać prawidłowy adres URL.',
    'ulid' => 'Pole :attribute musi zawierać prawidłowy identyfikator ULID.',
    'uuid' => 'Pole :attribute musi zawierać prawidłowy identyfikator UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Laravel resolves "validation.custom.<field>.<rule>" automatically, so a
    | FormRequest does NOT need a messages() method for these. Keep them for
    | fields where the generic wording would be confusing for the customer.
    |
    | WARNING: "custom" is keyed by the FIELD NAME, not by request — an entry
    | applies to every form that has a field with that name. Add one only if
    | the wording is right for all of them. Today: start_date.after_or_equal
    | assumes the rule is "after_or_equal:today" (cart, rental step 1); a form
    | using another date there needs its own wording.
    | Every entry exists in lang/en/validation.php too (parity test).
    |
    */

    'custom' => [
        'customer_type' => [
            'required' => 'Proszę wybrać typ klienta.',
            'in' => 'Nieprawidłowy typ klienta.',
        ],
        'settlement_method' => [
            'required' => 'Proszę wybrać sposób rozliczenia.',
            'in' => 'Wybrany sposób rozliczenia jest niedostępny.',
        ],
        'pickup_location_id' => [
            'required' => 'Wybierz oddział odbioru, aby złożyć zamówienie.',
            'exists' => 'Wybrany oddział odbioru jest niedostępny.',
        ],
        'customer_email' => [
            'required' => 'Adres email jest wymagany.',
            'email' => 'Podaj prawidłowy adres email.',
        ],
        'customer_phone' => [
            'required' => 'Numer telefonu jest wymagany.',
        ],
        'terms_accepted' => [
            'required' => 'Akceptacja regulaminu jest wymagana.',
            'accepted' => 'Musisz zaakceptować regulamin.',
        ],
        'rodo_accepted' => [
            'required' => 'Akceptacja polityki prywatności (RODO) jest wymagana.',
            'accepted' => 'Musisz zapoznać się z polityką prywatności.',
        ],
        'withdrawal_exclusion_accepted' => [
            'required' => 'Potwierdzenie wyłączenia prawa odstąpienia jest wymagane.',
            'accepted' => 'Musisz przyjąć do wiadomości wyłączenie prawa odstąpienia od umowy.',
        ],
        'customer_first_name' => [
            'required_if' => 'Imię jest wymagane dla osoby fizycznej.',
        ],
        'customer_last_name' => [
            'required_if' => 'Nazwisko jest wymagane dla osoby fizycznej.',
        ],
        'customer_pesel' => [
            'required' => 'PESEL jest wymagany dla osoby fizycznej.',
        ],
        'customer_street' => [
            'required_if' => 'Ulica jest wymagana.',
        ],
        'customer_building' => [
            'required_if' => 'Numer budynku jest wymagany.',
        ],
        'customer_city' => [
            'required_if' => 'Miasto jest wymagane.',
        ],
        'customer_postal_code' => [
            'required_if' => 'Kod pocztowy jest wymagany.',
        ],
        'invoice_company_name' => [
            'required_if' => 'Nazwa firmy jest wymagana.',
        ],
        'invoice_nip' => [
            'required_if' => 'NIP jest wymagany dla firmy.',
        ],
        'company_regon' => [
            'required_if' => 'REGON jest wymagany dla firmy.',
        ],
        'company_contact_name' => [
            'required_if' => 'Imię i nazwisko osoby podpisującej umowę jest wymagane.',
        ],
        'signatory_id_number' => [
            'required_if' => 'PESEL lub numer dowodu osoby podpisującej jest wymagany.',
        ],
        'pickup_person_id_number' => [
            'required_with' => 'Podaj numer dowodu osoby odbierającej sprzęt.',
        ],
        'invoice_street' => [
            'required_if' => 'Adres siedziby firmy (ulica) jest wymagany.',
        ],
        'invoice_street_number' => [
            'required_if' => 'Numer budynku siedziby firmy jest wymagany.',
        ],
        'invoice_postal_code' => [
            'required_if' => 'Kod pocztowy siedziby firmy jest wymagany.',
        ],
        'invoice_city' => [
            'required_if' => 'Miasto siedziby firmy jest wymagane.',
        ],
        'service_id' => [
            'exists' => 'Wybrana usługa nie istnieje.',
        ],
        'start_date' => [
            'after_or_equal' => 'Data rozpoczęcia musi być dzisiejsza lub przyszła.',
        ],
        'end_date' => [
            'after_or_equal' => 'Data zakończenia musi być równa lub późniejsza niż :date.',
        ],
        'quantity' => [
            'min' => 'Ilość musi wynosić co najmniej :min.',
        ],
        'phone' => [
            'regex' => 'Podaj prawidłowy numer telefonu.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Human-readable names for the real field names customers submit. A field
    | missing here is shown to the customer as its raw snake_case name.
    |
    */

    'attributes' => [
        // Account, login, registration, password reset
        'first_name' => 'imię',
        'last_name' => 'nazwisko',
        'email' => 'adres email',
        'password' => 'hasło',
        'password_confirmation' => 'potwierdzenie hasła',
        'current_password' => 'obecne hasło',
        'token' => 'token linku',
        'confirmation' => 'potwierdzenie',
        'phone' => 'numer telefonu',
        'phone_e164' => 'numer telefonu',
        'customer_type' => 'typ klienta',
        'nip' => 'NIP',
        'pesel' => 'PESEL',
        'regon' => 'REGON',
        'krs' => 'numer KRS/CEIDG',
        'company_name' => 'nazwa firmy',
        'billing_street' => 'ulica',
        'billing_building_number' => 'numer budynku',
        'billing_apartment_number' => 'numer lokalu',
        'billing_postal_code' => 'kod pocztowy',
        'billing_city' => 'miasto',
        'notify_email' => 'powiadomienia email',
        'notify_sms' => 'powiadomienia SMS',
        'sms_consent' => 'zgoda na SMS',

        // Saved addresses and vehicles (customer profile)
        'address' => 'adres',
        'nickname' => 'nazwa własna',
        'latitude' => 'szerokość geograficzna',
        'longitude' => 'długość geograficzna',
        'is_default' => 'ustawienie domyślne',
        'vehicle_type_id' => 'typ pojazdu',
        'car_brand_id' => 'marka',
        'car_brand_name' => 'marka',
        'car_model_id' => 'model',
        'car_model_name' => 'model',
        'custom_brand' => 'własna marka',
        'custom_model' => 'własny model',
        'vehicle_brand' => 'marka pojazdu',
        'vehicle_model' => 'model pojazdu',
        'vehicle_year' => 'rok produkcji',
        'year' => 'rok',
        'month' => 'miesiąc',
        'registration_number' => 'numer rejestracyjny',

        // Checkout
        'settlement_method' => 'sposób rozliczenia',
        'pickup_location_id' => 'oddział odbioru',
        'customer_email' => 'adres email',
        'customer_phone' => 'numer telefonu',
        'customer_first_name' => 'imię',
        'customer_last_name' => 'nazwisko',
        'customer_pesel' => 'PESEL',
        'customer_street' => 'ulica',
        'customer_building' => 'numer budynku',
        'customer_apartment' => 'numer lokalu',
        'customer_city' => 'miasto',
        'customer_postal_code' => 'kod pocztowy',
        'invoice_company_name' => 'nazwa firmy',
        'invoice_nip' => 'NIP',
        'invoice_street' => 'ulica',
        'invoice_street_number' => 'numer budynku',
        'invoice_postal_code' => 'kod pocztowy',
        'invoice_city' => 'miasto',
        'invoice_requested' => 'żądanie faktury',
        'company_regon' => 'REGON',
        'company_krs' => 'numer KRS/CEIDG',
        'company_contact_name' => 'imię i nazwisko osoby podpisującej',
        'signatory_id_number' => 'PESEL lub numer dowodu osoby podpisującej',
        'pickup_person_name' => 'imię i nazwisko osoby odbierającej sprzęt',
        'pickup_person_id_number' => 'numer dowodu osoby odbierającej sprzęt',
        'terms_accepted' => 'regulamin',
        'rodo_accepted' => 'polityka prywatności',
        'withdrawal_exclusion_accepted' => 'wyłączenie prawa odstąpienia',
        'save_to_profile' => 'zapis do profilu',

        // Cart, rental booking and extension
        'service_id' => 'usługa',
        'location_id' => 'oddział',
        'start_date' => 'data rozpoczęcia',
        'end_date' => 'data zakończenia',
        'new_end_date' => 'nowa data zakończenia',
        'quantity' => 'ilość',
        'customer_notes' => 'uwagi',
        'notes' => 'uwagi',

        // Service inquiry and contact forms
        'name' => 'imię i nazwisko',
        'message' => 'wiadomość',

        // Appointment booking (legacy appointment flow)
        'appointment_date' => 'data wizyty',
        'date' => 'data',
        'start_time' => 'godzina rozpoczęcia',
        'end_time' => 'godzina zakończenia',
        'time_slot' => 'termin',
        'access_notes' => 'uwagi dotyczące dojazdu',
        'location_address' => 'adres lokalizacji',
        'location_latitude' => 'szerokość geograficzna',
        'location_longitude' => 'długość geograficzna',
        'service_location_type' => 'typ lokalizacji',
        'street_name' => 'ulica',
        'street_number' => 'numer budynku',
        'city' => 'miasto',
        'postal_code' => 'kod pocztowy',
    ],

];
