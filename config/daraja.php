<?php

return [
    'base_url' => env('DARAJA_BASE_URL', 'https://sandbox.safaricom.co.ke'),
    'consumer_key' => env('DARAJA_CONSUMER_KEY'),
    'consumer_secret' => env('DARAJA_CONSUMER_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Shared / legacy shortcode (fallback)
    |--------------------------------------------------------------------------
    | Prefer DARAJA_STK_* and DARAJA_B2C_* below. DARAJA_SHORTCODE remains as a
    | fallback so older .env files keep working.
    */
    'shortcode' => env('DARAJA_SHORTCODE'),
    'passkey' => env('DARAJA_PASSKEY'),

    /*
    |--------------------------------------------------------------------------
    | Lipa Na M-Pesa Online (STK Push / C2B collect)
    |--------------------------------------------------------------------------
    | Sandbox defaults: shortcode 174379 + Lipa Na M-Pesa Online Passkey.
    */
    'stk' => [
        'shortcode' => env('DARAJA_STK_SHORTCODE') ?: env('DARAJA_SHORTCODE'),
        'passkey' => env('DARAJA_STK_PASSKEY') ?: env('DARAJA_PASSKEY'),
        'callback_url' => env('DARAJA_STK_CALLBACK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | B2C disbursement
    |--------------------------------------------------------------------------
    | Sandbox uses a different shortcode from STK (e.g. 600XXX) plus initiator.
    */
    'b2c' => [
        'shortcode' => env('DARAJA_B2C_SHORTCODE') ?: env('DARAJA_SHORTCODE'),
        'initiator_name' => env('DARAJA_INITIATOR_NAME'),
        'security_credential' => env('DARAJA_SECURITY_CREDENTIAL'),
        'result_url' => env('DARAJA_B2C_RESULT_URL'),
        'timeout_url' => env('DARAJA_B2C_TIMEOUT_URL'),
    ],

    // Legacy flat keys (still referenced in older docs / tooling)
    'initiator_name' => env('DARAJA_INITIATOR_NAME'),
    'security_credential' => env('DARAJA_SECURITY_CREDENTIAL'),
    'b2c_result_url' => env('DARAJA_B2C_RESULT_URL'),
    'b2c_timeout_url' => env('DARAJA_B2C_TIMEOUT_URL'),
    'stk_callback_url' => env('DARAJA_STK_CALLBACK_URL'),

    'sms_forwarder_secret' => env('SMS_FORWARDER_WEBHOOK_SECRET'),
    'fake' => (bool) env('DARAJA_FAKE', false),
    'payment_intent_ttl_minutes' => (int) env('PAYMENT_INTENT_TTL_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | STK success simulation (sandbox / assessment demos)
    |--------------------------------------------------------------------------
    | When null/empty: enabled automatically for Fake Daraja or sandbox base URL.
    | Set true/false to force. Ops UI posts a Daraja-shaped callback through the
    | same reconciliation pipeline (not a bypass of allocation rules).
    */
    'allow_stk_simulation' => env('DARAJA_ALLOW_STK_SIMULATION'),
];
