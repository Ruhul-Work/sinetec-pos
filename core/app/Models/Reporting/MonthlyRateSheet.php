<?php

namespace App\Models\Reporting;

use App\Enums\Reporting\MonthlyRateSheetStatus;
use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonthlyRateSheet extends Model
{
    protected $fillable = [
        'reporting_period_id',
        'sheet_no',
        'status',
        'note',
        'created_by',
        'approved_by',
        'approved_at',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MonthlyRateSheetStatus::class,
            'approved_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    public function rawMaterialRates(): HasMany
    {
        return $this->hasMany(MonthlyRawMaterialRate::class);
    }

    public function partRates(): HasMany
    {
        return $this->hasMany(MonthlyPartRate::class);
    }

    public function finishedProductRates(): HasMany
    {
        return $this->hasMany(MonthlyFinishedProductRate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
