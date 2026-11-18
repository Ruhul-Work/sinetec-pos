<?php

namespace App\Models\Sales;

use App\Models\backend\FinishedProduct;
use App\Models\backend\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'finished_product_id',
        'unit_id',
        'conversion_to_base',
        'quantity',
        'base_quantity',
        'unit_price',
        'discount',
        'tax',
        'line_total',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'conversion_to_base' => 'decimal:6',
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'line_total' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function finishedProduct(): BelongsTo
    {
        return $this->belongsTo(FinishedProduct::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
