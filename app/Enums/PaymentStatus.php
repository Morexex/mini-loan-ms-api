<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case Reversed = 'reversed';
}
