<?php

namespace App\Models\Inventory;

use App\Models\backend\RawMaterial;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawMaterialTransferItem extends StockTransferItem
{
    protected $fillable = [
        'stock_transfer_id', 'raw_material_id', 'unit_id', 'quantity', 'base_quantity', 'unit_cost',
    ];

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }
}
