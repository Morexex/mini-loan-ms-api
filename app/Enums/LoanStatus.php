<?php

namespace App\Enums;

enum LoanStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case DisbursementRequested = 'disbursement_requested';
    case Disbursed = 'disbursed';
    case Active = 'active';
    case Completed = 'completed';
    case Closed = 'closed';
}
