<?php

namespace App\Enums\Inventory;

enum StockAdjustmentStatus: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';
    case Posted = 'POSTED';
    case Void = 'VOID';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}
