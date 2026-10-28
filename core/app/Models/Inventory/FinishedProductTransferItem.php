<?php

namespace App\Models\Inventory;

use App\Models\backend\FinishedProduct;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinishedProductTransferItem extends StockTransferItem
{
    protected $fillable = [
        'stock_transfer_id', 'finished_product_id', 'unit_id', 'quantity', 'base_quantity', 'unit_cost',
    ];

    public function finishedProduct(): BelongsTo
    {
        return $this->belongsTo(FinishedProduct::class);
    }
}
