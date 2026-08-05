<?php

namespace App\Enums;

enum PaymentEvidenceSource: string
{
    case DarajaStk = 'daraja_stk';
    case SmsForwarder = 'sms_forwarder';
    case Manual = 'manual';
}
