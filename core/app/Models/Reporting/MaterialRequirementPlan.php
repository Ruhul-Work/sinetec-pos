<?php

namespace App\Models\Reporting;

use App\Enums\Reporting\MrpStatus;
use App\Models\backend\Branch;
use App\Models\backend\User;
use App\Models\Purchase\PurchaseOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialRequirementPlan extends Model
{
    protected $fillable = [
        'plan_no',
        'demand_plan_id',
        'branch_id',
        'reporting_period_id',
        'status',
        'note',
        'generated_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MrpStatus::class,
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function demandPlan(): BelongsTo
    {
        return $this->belongsTo(DemandPlan::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    public function finishedProductLines(): HasMany
    {
        return $this->hasMany(MrpFinishedProductLine::class)->orderBy('id');
    }

    public function componentLines(): HasMany
    {
        return $this->hasMany(MrpComponentLine::class)->orderBy('component_type')->orderBy('id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
