<?php

namespace App\Enums\Sales;

enum SaleReturnStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Void = 'VOID';

    public function label(): string
    {
        return str($this->value)->title()->toString();
    }
}
