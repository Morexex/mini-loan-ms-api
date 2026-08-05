<?php

namespace App\Enums;

enum InstallmentStatus: string
{
    case Scheduled = 'scheduled';
    case Due = 'due';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
}
