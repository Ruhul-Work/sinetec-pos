<?php

namespace App\Enums\Sales;

enum SaleChannel: string
{
    case Pos = 'POS';
    case Wholesale = 'WHOLESALE';

    public function label(): string
    {
        return match ($this) {
            self::Pos => 'POS',
            self::Wholesale => 'Wholesale',
        };
    }
}
