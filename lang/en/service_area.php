<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Service Area Language Lines (English)
    |--------------------------------------------------------------------------
    |
    | Translations for service area validation messages and waitlist forms
    |
    */

    'validation' => [
        'not_available' => 'Sorry, we currently don\'t service this location.',
        'outside_area' => 'This location is :distance km from the nearest service area (:city).',
        'checking' => 'Checking service area availability...',
        'success' => 'Great! We service your location',
        'success_detail' => 'You can continue with your booking',
    ],

    'waitlist' => [
        'title' => 'Notify me when you\'re available',
        'already_available' => 'This location is already within our service area!',
        'success' => 'Thank you! We\'ll notify you when we expand to your area.',
        'duplicate' => 'This email address is already on the waiting list for this location.',
        'error' => 'An error occurred while adding to the waiting list. Please try again.',
        'validation_error' => 'Failed to check service area. Please try again.',
    ],

    'form' => [
        'email' => 'Your email address',
        'email_placeholder' => 'john.doe@example.com',
        'name' => 'Full name',
        'name_placeholder' => 'John Doe',
        'phone' => 'Phone',
        'phone_placeholder' => '+1 234 567 890',
        'submit' => 'Notify me',
        'submitting' => 'Sending...',
        'optional' => '(optional)',
        'required' => '*',
    ],

    'alert' => [
        'location_unavailable' => 'Sorry, we don\'t service this location yet. Sign up for the waiting list to be notified when we become available in your area.',
        'wait_for_validation' => 'Please wait for service area availability check.',
    ],

    'admin' => [
        'resource_label' => 'Service area',
        'resource_plural' => 'Service areas',
        'waitlist_label' => 'Waitlist entry',
        'waitlist_plural' => 'Waitlist',
    ],
];
