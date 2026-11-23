<?php

namespace App\Models\Reporting;

use App\Enums\Reporting\ReportingPeriodStatus;
use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportingPeriod extends Model
{
    protected $fillable = [
        'year',
        'month',
        'starts_on',
        'ends_on',
        'status',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'status' => ReportingPeriodStatus::class,
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReportingPeriodEvent::class)->with('actor')->orderBy('id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(MonthlyReportSnapshot::class)->orderByDesc('version');
    }
}
