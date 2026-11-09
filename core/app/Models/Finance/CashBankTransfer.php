<?php

namespace App\Models\Finance;

use App\Models\backend\Account;
use App\Models\backend\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashBankTransfer extends Model
{
    protected $table = 'finance_cash_bank_transfers';

    protected $fillable = [
        'transfer_no',
        'branch_id',
        'from_account_id',
        'to_account_id',
        'transfer_date',
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
            'transfer_date' => 'immutable_date',
            'amount' => 'decimal:4',
            'posted_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }
}
