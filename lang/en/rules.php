<?php

// Messages of the custom rule objects in app/Rules (they call $fail(__('rules.…'))).
return [
    'nip' => [
        'length' => 'NIP must consist of 10 digits.',
        'digits' => 'NIP may only contain digits.',
        'checksum' => 'Invalid NIP number (checksum error).',
    ],
    'pesel' => [
        'length' => 'PESEL must consist of 11 digits.',
        'checksum' => 'Invalid PESEL number (checksum error).',
    ],
    'regon' => [
        'length' => 'REGON must consist of 9 or 14 digits.',
        'checksum' => 'Invalid REGON number (checksum error).',
    ],
];
