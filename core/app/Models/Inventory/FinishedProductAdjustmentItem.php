<?php

namespace App\Models\Inventory;

use App\Models\backend\FinishedProduct;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinishedProductAdjustmentItem extends StockAdjustmentItem
{
    protected $fillable = [
        'stock_adjustment_id', 'finished_product_id', 'unit_id', 'direction',
        'quantity', 'base_quantity', 'unit_cost', 'note',
    ];

    public function finishedProduct(): BelongsTo
    {
        return $this->belongsTo(FinishedProduct::class);
    }
}
