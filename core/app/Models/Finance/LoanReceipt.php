<?php

namespace App\Models\Finance;

use App\Enums\Finance\LoanReceiptStatus;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanReceipt extends Model
{
    protected $table = 'finance_loan_receipts';

    protected $fillable = [
        'loan_no',
        'branch_id',
        'loan_type',
        'cash_bank_account_id',
        'loan_account_id',
        'receipt_date',
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
            'receipt_date' => 'immutable_date',
            'amount' => 'decimal:4',
            'status' => LoanReceiptStatus::class,
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

    public function loanAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'loan_account_id');
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
