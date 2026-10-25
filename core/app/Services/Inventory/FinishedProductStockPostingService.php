<?php

namespace App\Services\Inventory;

use App\Enums\Inventory\StockDomain;

final class FinishedProductStockPostingService extends StockPostingService
{
    protected function domain(): StockDomain
    {
        return StockDomain::FinishedProduct;
    }
}
