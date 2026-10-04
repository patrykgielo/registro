<?php

// Messages of the custom rule objects in app/Rules (they call $fail(__('rules.…'))).
return [
    'nip' => [
        'length' => 'NIP musi składać się z 10 cyfr.',
        'digits' => 'NIP może zawierać tylko cyfry.',
        'checksum' => 'Nieprawidłowy numer NIP (błąd sumy kontrolnej).',
    ],
    'pesel' => [
        'length' => 'PESEL musi składać się z 11 cyfr.',
        'checksum' => 'Nieprawidłowy numer PESEL (błąd sumy kontrolnej).',
    ],
    'regon' => [
        'length' => 'REGON musi składać się z 9 lub 14 cyfr.',
        'checksum' => 'Nieprawidłowy numer REGON (błąd sumy kontrolnej).',
    ],
];
