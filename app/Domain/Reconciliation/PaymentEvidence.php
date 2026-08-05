<?php

namespace App\Domain\Reconciliation;

use App\Enums\PaymentEvidenceSource;
use Carbon\CarbonInterface;

final class PaymentEvidence
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly PaymentEvidenceSource $source,
        public readonly string $idempotencyKey,
        public readonly ?string $phone,
        public readonly ?string $amount,
        public readonly CarbonInterface $occurredAt,
        public readonly ?string $receiptNumber,
        public readonly ?string $checkoutRequestId,
        public readonly ?string $merchantRequestId,
        public readonly ?int $webhookLogId,
        public readonly array $raw = [],
    ) {}
}
