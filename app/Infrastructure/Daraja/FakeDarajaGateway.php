<?php

namespace App\Infrastructure\Daraja;

class FakeDarajaGateway implements DarajaGateway
{
    public static bool $shouldSucceed = true;

    public static ?string $failureMessage = 'Simulated Daraja failure';

    public function b2c(array $payload): array
    {
        $request = [
            'Amount' => $payload['amount'],
            'PartyB' => $payload['phone'],
            'Remarks' => $payload['remarks'] ?? 'Loan disbursement',
        ];

        if (! self::$shouldSucceed) {
            return [
                'request' => $request,
                'response' => [
                    'ResponseCode' => '1',
                    'ResponseDescription' => self::$failureMessage,
                ],
                'conversation_id' => null,
                'originator_conversation_id' => null,
                'response_code' => '1',
                'response_description' => self::$failureMessage,
                'successful' => false,
            ];
        }

        return [
            'request' => $request,
            'response' => [
                'ConversationID' => 'fake-conversation-id',
                'OriginatorConversationID' => 'fake-originator-id',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Accept the service request successfully.',
            ],
            'conversation_id' => 'fake-conversation-id',
            'originator_conversation_id' => 'fake-originator-id',
            'response_code' => '0',
            'response_description' => 'Accept the service request successfully.',
            'successful' => true,
        ];
    }

    public function stkPush(array $payload): array
    {
        if (! self::$shouldSucceed) {
            return [
                'request' => $payload,
                'response' => [
                    'ResponseCode' => '1',
                    'ResponseDescription' => self::$failureMessage,
                ],
                'merchant_request_id' => null,
                'checkout_request_id' => null,
                'response_code' => '1',
                'response_description' => self::$failureMessage,
                'successful' => false,
            ];
        }

        return [
            'request' => $payload,
            'response' => [
                'MerchantRequestID' => 'fake-merchant-id',
                'CheckoutRequestID' => 'fake-checkout-id',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing',
            ],
            'merchant_request_id' => 'fake-merchant-id',
            'checkout_request_id' => 'fake-checkout-id',
            'response_code' => '0',
            'response_description' => 'Success. Request accepted for processing',
            'successful' => true,
        ];
    }
}
