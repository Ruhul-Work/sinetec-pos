<?php

namespace App\Models\Inventory;

use App\Models\backend\Part;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartAdjustmentItem extends StockAdjustmentItem
{
    protected $fillable = [
        'stock_adjustment_id', 'part_id', 'unit_id', 'direction',
        'quantity', 'base_quantity', 'unit_cost', 'note',
    ];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
