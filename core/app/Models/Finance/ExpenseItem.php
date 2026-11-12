<?php

namespace App\Models\Finance;

use App\Models\Production\PartProductionExpense;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseItem extends Model
{
    protected $fillable = [
        'expense_id',
        'part_production_expense_id',
        'amount',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function productionExpense(): BelongsTo
    {
        return $this->belongsTo(
            PartProductionExpense::class,
            'part_production_expense_id',
        );
    }
}
