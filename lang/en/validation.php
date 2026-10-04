<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'The :attribute field must be accepted.',
    'accepted_if' => 'The :attribute field must be accepted when :other is :value.',
    'active_url' => 'The :attribute field must be a valid URL.',
    'after' => 'The :attribute field must be a date after :date.',
    'after_or_equal' => 'The :attribute field must be a date after or equal to :date.',
    'alpha' => 'The :attribute field must only contain letters.',
    'alpha_dash' => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
    'alpha_num' => 'The :attribute field must only contain letters and numbers.',
    'any_of' => 'The :attribute field is invalid.',
    'array' => 'The :attribute field must be an array.',
    'ascii' => 'The :attribute field must only contain single-byte alphanumeric characters and symbols.',
    'before' => 'The :attribute field must be a date before :date.',
    'before_or_equal' => 'The :attribute field must be a date before or equal to :date.',
    'between' => [
        'array' => 'The :attribute field must have between :min and :max items.',
        'file' => 'The :attribute field must be between :min and :max kilobytes.',
        'numeric' => 'The :attribute field must be between :min and :max.',
        'string' => 'The :attribute field must be between :min and :max characters.',
    ],
    'boolean' => 'The :attribute field must be true or false.',
    'can' => 'The :attribute field contains an unauthorized value.',
    'confirmed' => 'The :attribute field confirmation does not match.',
    'contains' => 'The :attribute field is missing a required value.',
    'current_password' => 'The password is incorrect.',
    'date' => 'The :attribute field must be a valid date.',
    'date_equals' => 'The :attribute field must be a date equal to :date.',
    'date_format' => 'The :attribute field must match the format :format.',
    'decimal' => 'The :attribute field must have :decimal decimal places.',
    'declined' => 'The :attribute field must be declined.',
    'declined_if' => 'The :attribute field must be declined when :other is :value.',
    'different' => 'The :attribute field and :other must be different.',
    'digits' => 'The :attribute field must be :digits digits.',
    'digits_between' => 'The :attribute field must be between :min and :max digits.',
    'dimensions' => 'The :attribute field has invalid image dimensions.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'doesnt_contain' => 'The :attribute field must not contain any of the following: :values.',
    'doesnt_end_with' => 'The :attribute field must not end with one of the following: :values.',
    'doesnt_start_with' => 'The :attribute field must not start with one of the following: :values.',
    'email' => 'The :attribute field must be a valid email address.',
    'encoding' => 'The :attribute field must be encoded in :encoding.',
    'ends_with' => 'The :attribute field must end with one of the following: :values.',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute is invalid.',
    'extensions' => 'The :attribute field must have one of the following extensions: :values.',
    'file' => 'The :attribute field must be a file.',
    'filled' => 'The :attribute field must have a value.',
    'gt' => [
        'array' => 'The :attribute field must have more than :value items.',
        'file' => 'The :attribute field must be greater than :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than :value.',
        'string' => 'The :attribute field must be greater than :value characters.',
    ],
    'gte' => [
        'array' => 'The :attribute field must have :value items or more.',
        'file' => 'The :attribute field must be greater than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
        'string' => 'The :attribute field must be greater than or equal to :value characters.',
    ],
    'hex_color' => 'The :attribute field must be a valid hexadecimal color.',
    'image' => 'The :attribute field must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'in_array' => 'The :attribute field must exist in :other.',
    'in_array_keys' => 'The :attribute field must contain at least one of the following keys: :values.',
    'integer' => 'The :attribute field must be an integer.',
    'ip' => 'The :attribute field must be a valid IP address.',
    'ipv4' => 'The :attribute field must be a valid IPv4 address.',
    'ipv6' => 'The :attribute field must be a valid IPv6 address.',
    'json' => 'The :attribute field must be a valid JSON string.',
    'list' => 'The :attribute field must be a list.',
    'lowercase' => 'The :attribute field must be lowercase.',
    'lt' => [
        'array' => 'The :attribute field must have less than :value items.',
        'file' => 'The :attribute field must be less than :value kilobytes.',
        'numeric' => 'The :attribute field must be less than :value.',
        'string' => 'The :attribute field must be less than :value characters.',
    ],
    'lte' => [
        'array' => 'The :attribute field must not have more than :value items.',
        'file' => 'The :attribute field must be less than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be less than or equal to :value.',
        'string' => 'The :attribute field must be less than or equal to :value characters.',
    ],
    'mac_address' => 'The :attribute field must be a valid MAC address.',
    'max' => [
        'array' => 'The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'max_digits' => 'The :attribute field must not have more than :max digits.',
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
        'file' => 'The :attribute field must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'min_digits' => 'The :attribute field must have at least :min digits.',
    'missing' => 'The :attribute field must be missing.',
    'missing_if' => 'The :attribute field must be missing when :other is :value.',
    'missing_unless' => 'The :attribute field must be missing unless :other is :value.',
    'missing_with' => 'The :attribute field must be missing when :values is present.',
    'missing_with_all' => 'The :attribute field must be missing when :values are present.',
    'multiple_of' => 'The :attribute field must be a multiple of :value.',
    'not_in' => 'The selected :attribute is invalid.',
    'not_regex' => 'The :attribute field format is invalid.',
    'numeric' => 'The :attribute field must be a number.',
    'password' => [
        'letters' => 'The :attribute field must contain at least one letter.',
        'mixed' => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute field must contain at least one number.',
        'symbols' => 'The :attribute field must contain at least one symbol.',
        'uncompromised' => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],
    'present' => 'The :attribute field must be present.',
    'present_if' => 'The :attribute field must be present when :other is :value.',
    'present_unless' => 'The :attribute field must be present unless :other is :value.',
    'present_with' => 'The :attribute field must be present when :values is present.',
    'present_with_all' => 'The :attribute field must be present when :values are present.',
    'prohibited' => 'The :attribute field is prohibited.',
    'prohibited_if' => 'The :attribute field is prohibited when :other is :value.',
    'prohibited_if_accepted' => 'The :attribute field is prohibited when :other is accepted.',
    'prohibited_if_declined' => 'The :attribute field is prohibited when :other is declined.',
    'prohibited_unless' => 'The :attribute field is prohibited unless :other is in :values.',
    'prohibits' => 'The :attribute field prohibits :other from being present.',
    'regex' => 'The :attribute field format is invalid.',
    'required' => 'The :attribute field is required.',
    'required_array_keys' => 'The :attribute field must contain entries for: :values.',
    'required_if' => 'The :attribute field is required when :other is :value.',
    'required_if_accepted' => 'The :attribute field is required when :other is accepted.',
    'required_if_declined' => 'The :attribute field is required when :other is declined.',
    'required_unless' => 'The :attribute field is required unless :other is in :values.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'required_without_all' => 'The :attribute field is required when none of :values are present.',
    'same' => 'The :attribute field must match :other.',
    'size' => [
        'array' => 'The :attribute field must contain :size items.',
        'file' => 'The :attribute field must be :size kilobytes.',
        'numeric' => 'The :attribute field must be :size.',
        'string' => 'The :attribute field must be :size characters.',
    ],
    'starts_with' => 'The :attribute field must start with one of the following: :values.',
    'string' => 'The :attribute field must be a string.',
    'timezone' => 'The :attribute field must be a valid timezone.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'uppercase' => 'The :attribute field must be uppercase.',
    'url' => 'The :attribute field must be a valid URL.',
    'ulid' => 'The :attribute field must be a valid ULID.',
    'uuid' => 'The :attribute field must be a valid UUID.',

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
    | Every entry exists in lang/pl/validation.php too (parity test).
    |
    */

    'custom' => [
        'customer_type' => [
            'required' => 'Please choose the customer type.',
            'in' => 'Invalid customer type.',
        ],
        'settlement_method' => [
            'required' => 'Please choose how you want to pay.',
            'in' => 'The selected payment method is not available.',
        ],
        'pickup_location_id' => [
            'required' => 'Choose a pickup branch to place the order.',
            'exists' => 'The selected pickup branch is not available.',
        ],
        'customer_email' => [
            'required' => 'Email address is required.',
            'email' => 'Enter a valid email address.',
        ],
        'customer_phone' => [
            'required' => 'Phone number is required.',
        ],
        'terms_accepted' => [
            'required' => 'You must accept the terms and conditions.',
            'accepted' => 'You must accept the terms and conditions.',
        ],
        'rodo_accepted' => [
            'required' => 'You must accept the privacy policy (GDPR).',
            'accepted' => 'You must read the privacy policy.',
        ],
        'withdrawal_exclusion_accepted' => [
            'required' => 'Please confirm that the right of withdrawal does not apply.',
            'accepted' => 'You must acknowledge that the right of withdrawal from the contract does not apply.',
        ],
        'customer_first_name' => [
            'required_if' => 'First name is required for a natural person.',
        ],
        'customer_last_name' => [
            'required_if' => 'Last name is required for a natural person.',
        ],
        'customer_pesel' => [
            'required' => 'PESEL is required for a natural person.',
        ],
        'customer_street' => [
            'required_if' => 'Street is required.',
        ],
        'customer_building' => [
            'required_if' => 'Building number is required.',
        ],
        'customer_city' => [
            'required_if' => 'City is required.',
        ],
        'customer_postal_code' => [
            'required_if' => 'Postal code is required.',
        ],
        'invoice_company_name' => [
            'required_if' => 'Company name is required.',
        ],
        'invoice_nip' => [
            'required_if' => 'NIP (tax ID) is required for a company.',
        ],
        'company_regon' => [
            'required_if' => 'REGON is required for a company.',
        ],
        'company_contact_name' => [
            'required_if' => 'The full name of the person signing the agreement is required.',
        ],
        'signatory_id_number' => [
            'required_if' => 'The PESEL or ID card number of the person signing the agreement is required.',
        ],
        'pickup_person_id_number' => [
            'required_with' => 'Enter the ID card number of the person collecting the equipment.',
        ],
        'invoice_street' => [
            'required_if' => 'The registered office street is required.',
        ],
        'invoice_street_number' => [
            'required_if' => 'The registered office building number is required.',
        ],
        'invoice_postal_code' => [
            'required_if' => 'The registered office postal code is required.',
        ],
        'invoice_city' => [
            'required_if' => 'The registered office city is required.',
        ],
        'service_id' => [
            'exists' => 'The selected service does not exist.',
        ],
        'start_date' => [
            'after_or_equal' => 'The start date must be today or in the future.',
        ],
        'end_date' => [
            'after_or_equal' => 'The end date must be the same as or later than :date.',
        ],
        'quantity' => [
            'min' => 'The quantity must be at least :min.',
        ],
        'phone' => [
            'regex' => 'Enter a valid phone number.',
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
        'first_name' => 'first name',
        'last_name' => 'last name',
        'email' => 'email address',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'current_password' => 'current password',
        'token' => 'link token',
        'confirmation' => 'confirmation',
        'phone' => 'phone number',
        'phone_e164' => 'phone number',
        'customer_type' => 'customer type',
        'nip' => 'NIP',
        'pesel' => 'PESEL',
        'regon' => 'REGON',
        'krs' => 'KRS/CEIDG number',
        'company_name' => 'company name',
        'billing_street' => 'street',
        'billing_building_number' => 'building number',
        'billing_apartment_number' => 'apartment number',
        'billing_postal_code' => 'postal code',
        'billing_city' => 'city',
        'notify_email' => 'email notifications',
        'notify_sms' => 'SMS notifications',
        'sms_consent' => 'SMS consent',

        // Saved addresses and vehicles (customer profile)
        'address' => 'address',
        'nickname' => 'custom name',
        'latitude' => 'latitude',
        'longitude' => 'longitude',
        'is_default' => 'default',
        'vehicle_type_id' => 'vehicle type',
        'car_brand_id' => 'brand',
        'car_brand_name' => 'brand',
        'car_model_id' => 'model',
        'car_model_name' => 'model',
        'custom_brand' => 'custom brand',
        'custom_model' => 'custom model',
        'vehicle_brand' => 'vehicle brand',
        'vehicle_model' => 'vehicle model',
        'vehicle_year' => 'production year',
        'year' => 'year',
        'month' => 'month',
        'registration_number' => 'registration number',

        // Checkout
        'settlement_method' => 'payment method',
        'pickup_location_id' => 'pickup branch',
        'customer_email' => 'email address',
        'customer_phone' => 'phone number',
        'customer_first_name' => 'first name',
        'customer_last_name' => 'last name',
        'customer_pesel' => 'PESEL',
        'customer_street' => 'street',
        'customer_building' => 'building number',
        'customer_apartment' => 'apartment number',
        'customer_city' => 'city',
        'customer_postal_code' => 'postal code',
        'invoice_company_name' => 'company name',
        'invoice_nip' => 'NIP',
        'invoice_street' => 'street',
        'invoice_street_number' => 'building number',
        'invoice_postal_code' => 'postal code',
        'invoice_city' => 'city',
        'invoice_requested' => 'invoice request',
        'company_regon' => 'REGON',
        'company_krs' => 'KRS/CEIDG number',
        'company_contact_name' => 'full name of the signatory',
        'signatory_id_number' => 'signatory PESEL or ID number',
        'pickup_person_name' => 'name of the person collecting the equipment',
        'pickup_person_id_number' => 'ID number of the person collecting the equipment',
        'terms_accepted' => 'terms and conditions',
        'rodo_accepted' => 'privacy policy',
        'withdrawal_exclusion_accepted' => 'withdrawal-right exclusion',
        'save_to_profile' => 'save to profile',

        // Cart, rental booking and extension
        'service_id' => 'service',
        'location_id' => 'branch',
        'start_date' => 'start date',
        'end_date' => 'end date',
        'new_end_date' => 'new end date',
        'quantity' => 'quantity',
        'customer_notes' => 'notes',
        'notes' => 'notes',

        // Service inquiry and contact forms
        'name' => 'name',
        'message' => 'message',

        // Appointment booking (legacy appointment flow)
        'appointment_date' => 'appointment date',
        'date' => 'date',
        'start_time' => 'start time',
        'end_time' => 'end time',
        'time_slot' => 'time slot',
        'access_notes' => 'access notes',
        'location_address' => 'location address',
        'location_latitude' => 'latitude',
        'location_longitude' => 'longitude',
        'service_location_type' => 'location type',
        'street_name' => 'street',
        'street_number' => 'building number',
        'city' => 'city',
        'postal_code' => 'postal code',
    ],

];
