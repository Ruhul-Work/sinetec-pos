<?php

namespace App\Models\Finance;

use App\Models\backend\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanRepayment extends Model
{
    protected $table = 'finance_loan_repayments';

    protected $fillable = [
        'repayment_no',
        'branch_id',
        'loan_receipt_id',
        'cash_bank_account_id',
        'repayment_date',
        'amount',
        'reference_no',
        'note',
        'status',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'repayment_date' => 'immutable_date',
            'amount' => 'decimal:4',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function loanReceipt(): BelongsTo
    {
        return $this->belongsTo(LoanReceipt::class);
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_bank_account_id');
    }
}
