<?php

namespace App\Models\Inventory;

use App\Models\backend\RawMaterial;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawMaterialAdjustmentItem extends StockAdjustmentItem
{
    protected $fillable = [
        'stock_adjustment_id', 'raw_material_id', 'unit_id', 'direction',
        'quantity', 'base_quantity', 'unit_cost', 'note',
    ];

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }
}
