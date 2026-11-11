<?php

namespace App\Models\Production;

use App\Models\backend\Account;
use App\Models\Finance\Expense;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PartProductionExpense extends Model
{
    protected $fillable = [
        'part_production_batch_id',
        'production_expense_category_id',
        'account_id',
        'amount',
        'allocation_basis',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PartProductionBatch::class, 'part_production_batch_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductionExpenseCategory::class, 'production_expense_category_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function expenseDocument(): HasOne
    {
        return $this->hasOne(Expense::class, 'part_production_expense_id');
    }
}
