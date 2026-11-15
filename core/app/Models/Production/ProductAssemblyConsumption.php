<?php

namespace App\Models\Production;

use App\Models\backend\Part;
use App\Models\backend\RawMaterial;
use App\Models\backend\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAssemblyConsumption extends Model
{
    protected $fillable = [
        'product_assembly_batch_id',
        'raw_material_id',
        'part_id',
        'unit_id',
        'quantity',
        'base_quantity',
        'unit_cost',
        'total_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductAssemblyBatch::class, 'product_assembly_batch_id');
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
