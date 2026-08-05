<?php

namespace App\Infrastructure\Daraja;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SandboxDarajaGateway implements DarajaGateway
{
    public function b2c(array $payload): array
    {
        $token = $this->accessToken();
        $url = rtrim((string) config('daraja.base_url'), '/').'/mpesa/b2c/v1/paymentrequest';

        $body = [
            'InitiatorName' => config('daraja.initiator_name'),
            'SecurityCredential' => config('daraja.security_credential'),
            'CommandID' => $payload['command_id'] ?? 'BusinessPayment',
            'Amount' => $payload['amount'],
            'PartyA' => config('daraja.shortcode'),
            'PartyB' => $payload['phone'],
            'Remarks' => $payload['remarks'] ?? 'Loan disbursement',
            'QueueTimeOutURL' => config('daraja.b2c_timeout_url'),
            'ResultURL' => config('daraja.b2c_result_url'),
            'Occasion' => $payload['occasion'] ?? 'Disbursement',
        ];

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->post($url, $body)
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'Daraja B2C request failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        return [
            'request' => $body,
            'response' => $response,
            'conversation_id' => $response['ConversationID'] ?? null,
            'originator_conversation_id' => $response['OriginatorConversationID'] ?? null,
            'response_code' => $response['ResponseCode'] ?? null,
            'response_description' => $response['ResponseDescription'] ?? null,
            'successful' => ($response['ResponseCode'] ?? null) === '0',
        ];
    }

    public function stkPush(array $payload): array
    {
        throw new RuntimeException('STK Push is implemented in Milestone 10.');
    }

    private function accessToken(): string
    {
        $key = config('daraja.consumer_key');
        $secret = config('daraja.consumer_secret');

        if (! $key || ! $secret) {
            throw new RuntimeException('Daraja consumer credentials are not configured.');
        }

        $url = rtrim((string) config('daraja.base_url'), '/').'/oauth/v1/generate?grant_type=client_credentials';

        $response = Http::withBasicAuth($key, $secret)
            ->acceptJson()
            ->get($url)
            ->throw()
            ->json();

        $token = $response['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Daraja OAuth response did not include an access token.');
        }

        return $token;
    }
}
