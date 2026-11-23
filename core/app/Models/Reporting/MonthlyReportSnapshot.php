<?php

namespace App\Models\Reporting;

use App\Enums\Reporting\MonthlyReportSnapshotScope;
use App\Enums\Reporting\MonthlyReportSnapshotStatus;
use App\Models\backend\Branch;
use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyReportSnapshot extends Model
{
    public const REPORT_TYPE = 'MONTHLY_PACKAGE';

    protected $fillable = [
        'reporting_period_id',
        'monthly_rate_sheet_id',
        'report_type',
        'scope_type',
        'branch_id',
        'scope_key',
        'version',
        'status',
        'snapshot_key',
        'filters',
        'payload',
        'checksum',
        'note',
        'supersedes_snapshot_id',
        'superseded_by',
        'superseded_at',
        'generated_by',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'branch_id' => 'integer',
            'scope_type' => MonthlyReportSnapshotScope::class,
            'status' => MonthlyReportSnapshotStatus::class,
            'filters' => 'array',
            'payload' => 'array',
            'generated_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    public function rateSheet(): BelongsTo
    {
        return $this->belongsTo(MonthlyRateSheet::class, 'monthly_rate_sheet_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_snapshot_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superseded_by');
    }

    public static function scopeKey(?int $branchId): string
    {
        return $branchId ? 'BRANCH:'.$branchId : 'COMPANY';
    }
}
