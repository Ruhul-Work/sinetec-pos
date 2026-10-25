<?php

namespace App\Models\Inventory;

use App\Models\backend\FinishedProduct;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinishedProductStockMovement extends StockMovement
{
    protected $fillable = [
        'finished_product_id',
        'branch_id',
        'warehouse_id',
        'movement_at',
        'movement_type',
        'direction',
        'quantity',
        'unit_cost',
        'source_type',
        'source_id',
        'source_item_id',
        'posting_key',
        'reversal_of_id',
        'note',
        'posted_by',
    ];

    public function finishedProduct(): BelongsTo
    {
        return $this->belongsTo(FinishedProduct::class);
    }
}
