<?php

namespace App\Models\Inventory;

use App\Models\backend\Part;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartStockBalance extends StockBalance
{
    protected $fillable = [
        'part_id',
        'warehouse_id',
        'quantity',
        'version',
    ];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
