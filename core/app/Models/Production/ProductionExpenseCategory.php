<?php

namespace App\Models\Production;

use App\Models\backend\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionExpenseCategory extends Model
{
    protected $fillable = [
        'code',
        'name',
        'expense_account_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function partProductionExpenses(): HasMany
    {
        return $this->hasMany(PartProductionExpense::class);
    }
}
