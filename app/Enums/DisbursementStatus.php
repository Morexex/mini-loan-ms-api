<?php

namespace App\Enums;

enum DisbursementStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Successful = 'successful';
    case Failed = 'failed';
}
