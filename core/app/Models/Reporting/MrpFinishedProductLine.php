<?php

namespace App\Models\Reporting;

use App\Models\backend\FinishedProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MrpFinishedProductLine extends Model
{
    protected $fillable = [
        'material_requirement_plan_id',
        'finished_product_id',
        'demand_quantity',
        'safety_quantity',
        'available_stock',
        'net_required',
    ];

    protected function casts(): array
    {
        return [
            'demand_quantity' => 'decimal:6',
            'safety_quantity' => 'decimal:6',
            'available_stock' => 'decimal:6',
            'net_required' => 'decimal:6',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaterialRequirementPlan::class, 'material_requirement_plan_id');
    }

    public function finishedProduct(): BelongsTo
    {
        return $this->belongsTo(FinishedProduct::class);
    }
}
