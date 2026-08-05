<?php

namespace App\Domain\Reconciliation\Support;

use App\Domain\Customers\Support\PhoneNormalizer;
use App\Domain\Reconciliation\PaymentEvidence;
use App\Enums\PaymentEvidenceSource;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class DarajaStkCallbackMapper
{
    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload, int $webhookLogId): PaymentEvidence
    {
        $callback = $payload['Body']['stkCallback'] ?? null;

        if (! is_array($callback)) {
            throw new InvalidArgumentException('Invalid Daraja STK callback payload.');
        }

        $checkoutRequestId = isset($callback['CheckoutRequestID']) ? (string) $callback['CheckoutRequestID'] : null;
        $merchantRequestId = isset($callback['MerchantRequestID']) ? (string) $callback['MerchantRequestID'] : null;
        $resultCode = (int) ($callback['ResultCode'] ?? -1);

        $items = collect($callback['CallbackMetadata']['Item'] ?? [])
            ->mapWithKeys(function ($item) {
                if (! is_array($item) || ! isset($item['Name'])) {
                    return [];
                }

                return [(string) $item['Name'] => $item['Value'] ?? null];
            });

        $amount = isset($items['Amount']) ? number_format((float) $items['Amount'], 2, '.', '') : null;
        $receipt = isset($items['MpesaReceiptNumber']) ? (string) $items['MpesaReceiptNumber'] : null;
        $phoneRaw = isset($items['PhoneNumber']) ? (string) $items['PhoneNumber'] : null;
        $phone = $phoneRaw ? $this->phoneNormalizer->tryNormalize($phoneRaw) : null;

        $occurredAt = now()->toImmutable();
        if (isset($items['TransactionDate'])) {
            $parsed = CarbonImmutable::createFromFormat('YmdHis', (string) $items['TransactionDate']);
            if ($parsed !== false) {
                $occurredAt = $parsed;
            }
        }

        $idempotencyKey = 'daraja_stk:'.($checkoutRequestId ?: $merchantRequestId ?: uniqid('stk_', true)).':'.$resultCode;

        return new PaymentEvidence(
            source: PaymentEvidenceSource::DarajaStk,
            idempotencyKey: $idempotencyKey,
            phone: $phone,
            amount: $amount,
            occurredAt: $occurredAt,
            receiptNumber: $receipt,
            checkoutRequestId: $checkoutRequestId,
            merchantRequestId: $merchantRequestId,
            webhookLogId: $webhookLogId,
            raw: $payload,
        );
    }
}
