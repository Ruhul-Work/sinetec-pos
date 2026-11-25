<?php

namespace App\Models\Reporting;

use App\Models\backend\Part;
use App\Models\backend\RawMaterial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MrpComponentLine extends Model
{
    protected $fillable = [
        'material_requirement_plan_id',
        'component_type',
        'component_key',
        'raw_material_id',
        'part_id',
        'gross_requirement',
        'available_stock',
        'safety_quantity',
        'incoming_quantity',
        'net_requirement',
        'make_quantity',
        'buy_quantity',
        'source_hint',
    ];

    protected function casts(): array
    {
        return [
            'gross_requirement' => 'decimal:6',
            'available_stock' => 'decimal:6',
            'safety_quantity' => 'decimal:6',
            'incoming_quantity' => 'decimal:6',
            'net_requirement' => 'decimal:6',
            'make_quantity' => 'decimal:6',
            'buy_quantity' => 'decimal:6',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaterialRequirementPlan::class, 'material_requirement_plan_id');
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function component(): RawMaterial|Part|null
    {
        return $this->component_type === 'RAW_MATERIAL'
            ? $this->rawMaterial
            : $this->part;
    }

    public function componentName(): string
    {
        return $this->component_type === 'RAW_MATERIAL'
            ? ($this->rawMaterial?->name ?? '—')
            : ($this->part?->name ?? '—');
    }

    public function componentCode(): string
    {
        return $this->component_type === 'RAW_MATERIAL'
            ? ($this->rawMaterial?->material_code ?? '')
            : ($this->part?->part_code ?? '');
    }
}
