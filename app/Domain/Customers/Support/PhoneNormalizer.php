<?php

namespace App\Domain\Customers\Support;

use InvalidArgumentException;

class PhoneNormalizer
{
    /**
     * Normalize Kenyan MSISDNs to digits-only international form (2547XXXXXXXX).
     */
    public function normalize(string $phone): string
    {
        $trimmed = trim($phone);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '254'.substr($digits, 1);
        }

        if (str_starts_with($digits, '7') && strlen($digits) === 9) {
            $digits = '254'.$digits;
        }

        if (! preg_match('/^2547\d{8}$/', $digits)) {
            throw new InvalidArgumentException('Phone number must be a valid Kenyan mobile MSISDN.');
        }

        return $digits;
    }

    public function tryNormalize(string $phone): ?string
    {
        try {
            return $this->normalize($phone);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
