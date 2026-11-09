<?php

namespace App\Models\Finance;

use App\Enums\Finance\CapitalInvestmentStatus;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapitalInvestment extends Model
{
    protected $table = 'finance_capital_investments';

    protected $fillable = [
        'investment_no',
        'branch_id',
        'investment_type',
        'cash_bank_account_id',
        'capital_account_id',
        'investment_date',
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
            'investment_date' => 'immutable_date',
            'amount' => 'decimal:4',
            'status' => CapitalInvestmentStatus::class,
            'posted_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_bank_account_id');
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
