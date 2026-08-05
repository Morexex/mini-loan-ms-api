<?php

namespace App\Enums;

enum WalletTransactionReason: string
{
    case Overpayment = 'overpayment';
    case RepaymentDrawdown = 'repayment_drawdown';
    case Adjustment = 'adjustment';
}
