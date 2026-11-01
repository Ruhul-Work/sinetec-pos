<?php

namespace App\Models\Purchase;

use App\Models\backend\Part;
use App\Models\backend\RawMaterial;
use App\Models\backend\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReceiptItem extends Model
{
    protected $table = 'purchase_receipt_item';

    protected $fillable = [
        'purchase_receipt_id', 'purchase_order_item_id', 'raw_material_id', 'part_id',
        'unit_id', 'conversion_to_base', 'quantity', 'base_quantity', 'unit_rate', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'conversion_to_base' => 'decimal:6', 'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6', 'unit_rate' => 'decimal:4', 'line_total' => 'decimal:4',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class, 'purchase_receipt_item_id');
    }
}
