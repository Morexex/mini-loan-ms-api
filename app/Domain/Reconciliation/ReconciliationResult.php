<?php

namespace App\Domain\Reconciliation;

final class ReconciliationResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $paymentIntentUuid = null,
        public readonly ?string $message = null,
    ) {}

    public static function ignoredDuplicate(): self
    {
        return new self(outcome: 'ignored_duplicate', message: 'Evidence already processed.');
    }

    public static function unmatched(string $message): self
    {
        return new self(outcome: 'unmatched', message: $message);
    }

    public static function accepted(string $paymentIntentUuid, string $message = 'Evidence accepted for later allocation.'): self
    {
        return new self(outcome: 'accepted', paymentIntentUuid: $paymentIntentUuid, message: $message);
    }

    public static function failed(string $message): self
    {
        return new self(outcome: 'failed', message: $message);
    }
}
