<?php

namespace App\Models\Inventory;

use App\Models\backend\FinishedProduct;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinishedProductStockBalance extends StockBalance
{
    protected $fillable = [
        'finished_product_id',
        'warehouse_id',
        'quantity',
        'version',
    ];

    public function finishedProduct(): BelongsTo
    {
        return $this->belongsTo(FinishedProduct::class);
    }
}
