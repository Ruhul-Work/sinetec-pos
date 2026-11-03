<?php

namespace App\Enums\Purchase;

enum PurchasePaymentStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Void = 'VOID';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted · Paid',
            self::Void => 'Void',
        };
    }
}
