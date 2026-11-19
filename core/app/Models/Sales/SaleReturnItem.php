<?php

namespace App\Models\Sales;

use App\Models\backend\FinishedProduct;
use App\Models\backend\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    protected $fillable = [
        'sale_return_id',
        'sale_item_id',
        'finished_product_id',
        'unit_id',
        'quantity',
        'base_quantity',
        'unit_price',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
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
