<?php

namespace App\Models\Inventory;

use App\Models\backend\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class StockBalance extends Model
{
    protected $fillable = [
        'warehouse_id',
        'quantity',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'version' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
