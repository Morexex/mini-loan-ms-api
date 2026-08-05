<?php

namespace App\Enums;

enum PaymentIntentStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case AwaitingCallback = 'awaiting_callback';
    case Matched = 'matched';
    case Allocated = 'allocated';
    case Completed = 'completed';
    case Expired = 'expired';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
