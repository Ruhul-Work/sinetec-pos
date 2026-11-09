<?php

namespace App\Models\Production;

use App\Models\backend\RawMaterial;
use App\Models\backend\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartBomItem extends Model
{
    protected $fillable = [
        'part_bom_id',
        'raw_material_id',
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
        return $this->belongsTo(PartBom::class, 'part_bom_id');
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
