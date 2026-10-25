<?php

namespace App\Models\Inventory;

use App\Models\backend\RawMaterial;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawMaterialStockMovement extends StockMovement
{
    protected $fillable = [
        'raw_material_id',
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

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }
}
