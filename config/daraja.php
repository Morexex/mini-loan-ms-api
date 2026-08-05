<?php

return [
    'base_url' => env('DARAJA_BASE_URL', 'https://sandbox.safaricom.co.ke'),
    'consumer_key' => env('DARAJA_CONSUMER_KEY'),
    'consumer_secret' => env('DARAJA_CONSUMER_SECRET'),
    'shortcode' => env('DARAJA_SHORTCODE'),
    'passkey' => env('DARAJA_PASSKEY'),
    'initiator_name' => env('DARAJA_INITIATOR_NAME'),
    'security_credential' => env('DARAJA_SECURITY_CREDENTIAL'),
    'b2c_result_url' => env('DARAJA_B2C_RESULT_URL'),
    'b2c_timeout_url' => env('DARAJA_B2C_TIMEOUT_URL'),
    'stk_callback_url' => env('DARAJA_STK_CALLBACK_URL'),
    'sms_forwarder_secret' => env('SMS_FORWARDER_WEBHOOK_SECRET'),
    'fake' => (bool) env('DARAJA_FAKE', false),
    'payment_intent_ttl_minutes' => (int) env('PAYMENT_INTENT_TTL_MINUTES', 15),
];
