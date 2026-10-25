<?php

namespace App\Models\backend;

use App\Models\Inventory\FinishedProductStockBalance;
use App\Models\Inventory\FinishedProductStockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinishedProduct extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_code',
        'barcode',
        'name',
        'finished_product_category_id',
        'base_unit_id',
        'brand_id',
        'retail_price',
        'wholesale_price',
        'specification',
        'description',
        'image_path',
        'is_active',
    ];

    protected $casts = [
        'retail_price' => 'decimal:4',
        'wholesale_price' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinishedProductCategory::class, 'finished_product_category_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(FinishedProductStockMovement::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(FinishedProductStockBalance::class);
    }
}
