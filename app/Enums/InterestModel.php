<?php

namespace App\Enums;

enum InterestModel: string
{
    case Flat = 'flat';

    public function label(): string
    {
        return match ($this) {
            self::Flat => 'Flat interest',
        };
    }
}
