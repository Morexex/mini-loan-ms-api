<?php

namespace Tests\Unit\Domain\Customers;

use App\Domain\Customers\Support\PhoneNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneNormalizerTest extends TestCase
{
    #[DataProvider('validPhones')]
    public function test_it_normalizes_valid_kenyan_mobiles(string $input, string $expected): void
    {
        $this->assertSame($expected, (new PhoneNormalizer)->normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validPhones(): array
    {
        return [
            'local_zero' => ['0712345678', '254712345678'],
            'plus_intl' => ['+254712345678', '254712345678'],
            'intl_digits' => ['254712345678', '254712345678'],
            'spaced' => ['07 1234 5678', '254712345678'],
        ];
    }

    public function test_it_rejects_invalid_numbers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PhoneNormalizer)->normalize('12345');
    }
}
