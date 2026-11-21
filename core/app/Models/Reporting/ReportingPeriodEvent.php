<?php

namespace App\Models\Reporting;

use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportingPeriodEvent extends Model
{
    protected $fillable = [
        'reporting_period_id',
        'action',
        'actor_id',
        'reason',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
