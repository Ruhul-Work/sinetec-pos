<?php

namespace App\Services\Inventory;

use App\Enums\Inventory\StockDomain;

final class PartStockPostingService extends StockPostingService
{
    protected function domain(): StockDomain
    {
        return StockDomain::Part;
    }
}
