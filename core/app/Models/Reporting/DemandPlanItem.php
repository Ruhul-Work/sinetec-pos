<?php

namespace App\Models\Reporting;

use App\Models\backend\FinishedProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandPlanItem extends Model
{
    protected $fillable = [
        'demand_plan_id',
        'finished_product_id',
        'historical_net_quantity',
        'suggested_quantity',
        'forecast_quantity',
        'safety_quantity',
        'basis',
    ];

    protected function casts(): array
    {
        return [
            'historical_net_quantity' => 'decimal:6',
            'suggested_quantity' => 'decimal:6',
            'forecast_quantity' => 'decimal:6',
            'safety_quantity' => 'decimal:6',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DemandPlan::class, 'demand_plan_id');
    }

    public function finishedProduct(): BelongsTo
    {
        return $this->belongsTo(FinishedProduct::class);
    }
}
