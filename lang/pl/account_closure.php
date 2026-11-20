<?php

declare(strict_types=1);

return [
    'no_recipient' => [
        'title' => 'Nie udało się wysłać wniosku',
        'body' => 'Adres, na który trafiają wnioski o zamknięcie konta, nie jest ustawiony. Wniosek NIE został złożony — skontaktuj się z obsługą platformy.',
    ],

    'send_failed' => [
        'title' => 'Nie udało się złożyć wniosku',
        'body' => 'Nie udało się przekazać wniosku operatorowi platformy, więc NIE został złożony. Spróbuj ponownie za chwilę albo skontaktuj się z obsługą platformy.',
    ],

    'mail' => [
        'greeting' => 'Dzień dobry!',
        'salutation' => 'Pozdrawiamy, System Registro',
        'unknown_requester' => 'nieznany',
        'withdrawn' => [
            'subject' => 'Wniosek o zamknięcie konta wycofany — :org',
            'intro' => 'Organizacja **:org** (slug: `:slug`) wycofała wniosek o zamknięcie konta.',
            'by' => 'Wycofujący: **:who**',
            'next' => 'Konto pozostaje aktywne. Nie podejmuj działań offboardingowych w związku z poprzednim wnioskiem.',
        ],
    ],

    'withdraw' => [
        'label' => 'Wycofaj wniosek',
        'modal_heading' => 'Wycofać wniosek o zamknięcie konta?',
        'modal_description' => 'Wniosek zostanie anulowany, a konto pozostanie aktywne. W razie potrzeby możesz złożyć nowy wniosek w dowolnym momencie.',
        'submit' => 'Tak, wycofaj wniosek',
        'done_title' => 'Wniosek wycofany',
        'done_body' => 'Wniosek o zamknięcie konta został wycofany. Konto działa bez zmian.',
        'done_operator_not_told' => 'Wniosek został wycofany, ale nie udało się automatycznie powiadomić operatora platformy. Dla pewności poinformuj obsługę.',
        'nothing_pending' => 'Brak wniosku do wycofania',
        'too_late' => 'Konto jest już w trakcie zamykania — wniosku nie można wycofać. Skontaktuj się z administratorem.',
    ],
];
