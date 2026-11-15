<?php

namespace App\Models\Production;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAssemblyExpense extends Model
{
    protected $fillable = [
        'product_assembly_batch_id',
        'production_expense_category_id',
        'account_id',
        'amount',
        'allocation_basis',
        'note',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductAssemblyBatch::class, 'product_assembly_batch_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductionExpenseCategory::class, 'production_expense_category_id');
    }
}
