<?php

namespace App\Infrastructure\Daraja;

/**
 * Outbound port for Safaricom Daraja sandbox operations.
 * Implemented in later milestones; kept as a contract stub for Approach B.
 */
interface DarajaGateway
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function b2c(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function stkPush(array $payload): array;
}
