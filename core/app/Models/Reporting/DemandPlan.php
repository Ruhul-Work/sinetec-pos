<?php

namespace App\Models\Reporting;

use App\Enums\Reporting\DemandPlanStatus;
use App\Models\backend\Branch;
use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DemandPlan extends Model
{
    protected $fillable = [
        'plan_no',
        'branch_id',
        'reporting_period_id',
        'history_months',
        'status',
        'note',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'history_months' => 'integer',
            'status' => DemandPlanStatus::class,
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DemandPlanItem::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function materialRequirementPlan(): HasOne
    {
        return $this->hasOne(MaterialRequirementPlan::class);
    }
}
