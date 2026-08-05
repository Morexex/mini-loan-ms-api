<?php

namespace App\Domain\Reconciliation\Support;

use App\Domain\Customers\Support\PhoneNormalizer;
use App\Domain\Reconciliation\PaymentEvidence;
use App\Enums\PaymentEvidenceSource;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class SmsForwarderPayloadMapper
{
    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
    ) {}

    /**
     * Expected minimal payload until Q1 sample arrives:
     * { "text": "...", "from": "MPESA", "received_at": "...", "phone"?: "...", "amount"?: "...", "receipt"?: "..." }
     *
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload, int $webhookLogId): PaymentEvidence
    {
        $text = (string) ($payload['text'] ?? $payload['body'] ?? '');
        if ($text === '' && ! isset($payload['amount'], $payload['phone'])) {
            throw new InvalidArgumentException('SMS forwarder payload missing text/body or structured fields.');
        }

        $amount = isset($payload['amount'])
            ? number_format((float) $payload['amount'], 2, '.', '')
            : $this->extractAmount($text);

        $phoneRaw = isset($payload['phone']) ? (string) $payload['phone'] : $this->extractPhone($text);
        $phone = $phoneRaw ? $this->phoneNormalizer->tryNormalize($phoneRaw) : null;
        $receipt = isset($payload['receipt']) ? (string) $payload['receipt'] : $this->extractReceipt($text);

        $occurredAt = isset($payload['received_at'])
            ? CarbonImmutable::parse((string) $payload['received_at'])
            : now()->toImmutable();

        $idempotencyKey = 'sms_forwarder:'.sha1($text.'|'.($receipt ?? '').'|'.($amount ?? '').'|'.$occurredAt->toIso8601String());

        return new PaymentEvidence(
            source: PaymentEvidenceSource::SmsForwarder,
            idempotencyKey: $idempotencyKey,
            phone: $phone,
            amount: $amount,
            occurredAt: $occurredAt,
            receiptNumber: $receipt,
            checkoutRequestId: null,
            merchantRequestId: null,
            webhookLogId: $webhookLogId,
            raw: $payload,
        );
    }

    private function extractAmount(string $text): ?string
    {
        if (preg_match('/Ksh[.\s]*([\d,]+\.?\d*)/i', $text, $matches)) {
            return number_format((float) str_replace(',', '', $matches[1]), 2, '.', '');
        }

        return null;
    }

    private function extractPhone(string $text): ?string
    {
        if (preg_match('/(?:from|to)\s+(?:\w+\s+)?(2547\d{8}|07\d{8})/i', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractReceipt(string $text): ?string
    {
        if (preg_match('/\b([A-Z0-9]{10})\b/', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
