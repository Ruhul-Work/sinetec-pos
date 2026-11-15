<?php

namespace App\Models\Production;

use App\Models\backend\Part;
use App\Models\backend\RawMaterial;
use App\Models\backend\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBomItem extends Model
{
    protected $fillable = [
        'product_bom_id',
        'raw_material_id',
        'part_id',
        'unit_id',
        'required_quantity',
        'base_quantity',
        'wastage_percent',
    ];

    protected $casts = [
        'required_quantity' => 'decimal:6',
        'base_quantity' => 'decimal:6',
        'wastage_percent' => 'decimal:4',
    ];

    public function bom(): BelongsTo
    {
        return $this->belongsTo(ProductBom::class, 'product_bom_id');
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

    public function componentType(): string
    {
        return $this->raw_material_id !== null ? 'RAW_MATERIAL' : 'PART';
    }

    public function component(): RawMaterial|Part|null
    {
        return $this->raw_material_id !== null ? $this->rawMaterial : $this->part;
    }
}
