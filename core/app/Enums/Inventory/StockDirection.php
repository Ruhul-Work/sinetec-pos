<?php

namespace App\Enums\Inventory;

enum StockDirection: string
{
    case In = 'IN';
    case Out = 'OUT';

    public function opposite(): self
    {
        return $this === self::In ? self::Out : self::In;
    }
}
