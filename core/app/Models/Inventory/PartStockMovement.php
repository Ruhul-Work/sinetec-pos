<?php

namespace App\Models\Inventory;

use App\Models\backend\Part;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartStockMovement extends StockMovement
{
    protected $fillable = [
        'part_id',
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

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
