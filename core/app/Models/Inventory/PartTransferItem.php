<?php

namespace App\Models\Inventory;

use App\Models\backend\Part;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartTransferItem extends StockTransferItem
{
    protected $fillable = [
        'stock_transfer_id', 'part_id', 'unit_id', 'quantity', 'base_quantity', 'unit_cost',
    ];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
