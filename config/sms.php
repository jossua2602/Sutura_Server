<?php

return [
    // log        = TEST MODE (default): nothing leaves the server, the text is written to the log and shown as "test".
    // semaphore  = real delivery through Semaphore (https://semaphore.co), a Philippine SMS provider.
    // iprogsms   = real delivery through IPROG SMS (https://www.iprogsms.com): small GCash top-ups, default sender.
    'driver' => env('SMS_DRIVER', 'log'),

    'iprogsms' => [
        'api_token' => env('IPROGSMS_API_TOKEN'),
        // Optional route (0, 1 or 2, per IPROG's docs); empty = their default.
        'sms_provider' => env('IPROGSMS_PROVIDER'),
        'endpoint' => 'https://www.iprogsms.com/api/v1/sms_messages',
    ],

    'semaphore' => [
        'api_key' => env('SEMAPHORE_API_KEY'),
        'sender_name' => env('SEMAPHORE_SENDER_NAME'),   // optional, must be registered with the provider
        'endpoint' => 'https://api.semaphore.co/api/v4/messages',
    ],

    // Outside production a real driver only texts these numbers (comma-separated, any PH format) — so a
    // test database full of made-up customers can never message a stranger.
    'test_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('SMS_TEST_ALLOWLIST', ''))))),

    // Hard stop on how many texts one shop can send per day (cost control).
    'daily_cap' => (int) env('SMS_DAILY_CAP', 100),
];
