<?php

namespace App\Models\backend;

use App\Models\Inventory\RawMaterialStockBalance;
use App\Models\Inventory\RawMaterialStockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RawMaterial extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'material_code',
        'barcode',
        'name',
        'raw_material_category_id',
        'base_unit_id',
        'brand_id',
        'material_kind',
        'specification',
        'description',
        'image_path',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(RawMaterialCategory::class, 'raw_material_category_id');
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
        return $this->hasMany(RawMaterialStockMovement::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(RawMaterialStockBalance::class);
    }
}
