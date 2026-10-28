<?php

namespace App\Enums\Inventory;

enum StockTransferStatus: string
{
    case Draft = 'DRAFT';
    case InTransit = 'IN_TRANSIT';
    case Posted = 'POSTED';
    case Void = 'VOID';

    public function label(): string
    {
        return match ($this) {
            self::InTransit => 'In Transit',
            default => ucfirst(strtolower($this->value)),
        };
    }
}
