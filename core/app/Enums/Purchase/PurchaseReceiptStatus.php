<?php

namespace App\Enums\Purchase;

enum PurchaseReceiptStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Void = 'VOID';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
