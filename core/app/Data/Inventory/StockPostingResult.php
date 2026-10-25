<?php

namespace App\Data\Inventory;

use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockMovement;

final readonly class StockPostingResult
{
    public function __construct(
        public StockMovement $movement,
        public StockBalance $balance,
        public bool $wasAlreadyPosted = false,
    ) {}
}
