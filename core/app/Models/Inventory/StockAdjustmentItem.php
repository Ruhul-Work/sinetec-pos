<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\StockDirection;
use App\Models\backend\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class StockAdjustmentItem extends Model
{
    protected $fillable = [
        'stock_adjustment_id',
        'unit_id',
        'direction',
        'quantity',
        'base_quantity',
        'unit_cost',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'direction' => StockDirection::class,
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
