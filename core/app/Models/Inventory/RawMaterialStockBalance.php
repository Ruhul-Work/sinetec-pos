<?php

namespace App\Models\Inventory;

use App\Models\backend\RawMaterial;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawMaterialStockBalance extends StockBalance
{
    protected $fillable = [
        'raw_material_id',
        'warehouse_id',
        'quantity',
        'version',
    ];

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }
}
