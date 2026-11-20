<?php

declare(strict_types=1);

return [
    'no_recipient' => [
        'title' => 'The request could not be sent',
        'body' => 'The address that receives account closure requests is not set. The request was NOT submitted — please contact the platform operator.',
    ],

    'send_failed' => [
        'title' => 'The request could not be filed',
        'body' => 'The request could not be passed to the platform operator, so it was NOT filed. Please try again in a moment or contact the platform operator.',
    ],

    'mail' => [
        'greeting' => 'Hello!',
        'salutation' => 'Regards, Registro System',
        'unknown_requester' => 'unknown',
        'withdrawn' => [
            'subject' => 'Account closure request withdrawn — :org',
            'intro' => 'The organisation **:org** (slug: `:slug`) has withdrawn its account closure request.',
            'by' => 'Withdrawn by: **:who**',
            'next' => 'The account stays active. Do not start offboarding on the strength of the earlier request.',
        ],
    ],

    'withdraw' => [
        'label' => 'Withdraw request',
        'modal_heading' => 'Withdraw the account closure request?',
        'modal_description' => 'The request will be cancelled and the account stays active. You can submit a new request at any time.',
        'submit' => 'Yes, withdraw the request',
        'done_title' => 'Request withdrawn',
        'done_body' => 'The account closure request has been withdrawn. The account carries on as before.',
        'done_operator_not_told' => 'The request has been withdrawn, but the platform operator could not be notified automatically. Please let them know yourself to be safe.',
        'nothing_pending' => 'There is no request to withdraw',
        'too_late' => 'The account is already being closed — the request can no longer be withdrawn. Please contact the administrator.',
    ],
];
