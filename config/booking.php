<?php

return [
    'public_availability_rate_limit' => (int) env(
        'PUBLIC_AVAILABILITY_RATE_LIMIT',
        60,
    ),
    'public_submission_rate_limit' => (int) env(
        'PUBLIC_BOOKING_SUBMISSION_RATE_LIMIT',
        5,
    ),
    'management_read_rate_limit' => (int) env(
        'BOOKING_MANAGEMENT_READ_RATE_LIMIT',
        30,
    ),
    'reschedule_rate_limit' => (int) env(
        'BOOKING_RESCHEDULE_RATE_LIMIT',
        5,
    ),
    'cancel_rate_limit' => (int) env(
        'BOOKING_CANCEL_RATE_LIMIT',
        5,
    ),
    'minimum_form_seconds' => (int) env('BOOKING_MINIMUM_FORM_SECONDS', 3),
    'maximum_form_seconds' => (int) env('BOOKING_MAXIMUM_FORM_SECONDS', 7200),
    'maximum_request_bytes' => (int) env('BOOKING_MAXIMUM_REQUEST_BYTES', 16384),
    'turnstile_site_key' => env('TURNSTILE_SITE_KEY'),
    'turnstile_secret_key' => env('TURNSTILE_SECRET_KEY'),
];
