<?php

namespace App\Models\Finance;

use App\Enums\Finance\FiscalYearClosingStatus;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\FiscalYear;
use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalYearClosing extends Model
{
    protected $table = 'finance_fiscal_year_closings';

    protected $fillable = [
        'closing_no',
        'fiscal_year_id',
        'branch_id',
        'drawings_account_id',
        'capital_account_id',
        'closing_date',
        'amount',
        'reference_no',
        'note',
        'status',
        'created_by',
        'posted_by',
        'posted_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'closing_date' => 'immutable_date',
            'amount' => 'decimal:4',
            'status' => FiscalYearClosingStatus::class,
            'posted_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function drawingsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'drawings_account_id');
    }

    public function capitalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'capital_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
