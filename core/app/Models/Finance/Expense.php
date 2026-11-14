<?php

namespace App\Models\Finance;

use App\Enums\Finance\ExpenseStatus;
use App\Models\backend\Branch;
use App\Models\backend\JournalEntry;
use App\Models\backend\User;
use App\Models\Production\PartProductionBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Expense extends Model
{
    protected $table = 'expenses';

    protected $fillable = [
        'expense_no',
        'branch_id',
        'expense_category_id',
        'part_production_batch_id',
        'expense_date',
        'payee',
        'amount',
        'status',
        'description',
        'created_by',
        'approved_by',
        'approved_at',
        'posted_by',
        'posted_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'immutable_date',
            'amount' => 'decimal:4',
            'status' => ExpenseStatus::class,
            'approved_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function productionBatch(): BelongsTo
    {
        // Historical compatibility for production settlements created before the stock-only policy.
        return $this->belongsTo(PartProductionBatch::class, 'part_production_batch_id');
    }

    public function items(): HasMany
    {
        // Historical settlement lines remain readable, but new General Expenses do not create them.
        return $this->hasMany(ExpenseItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(ExpensePayment::class, 'expense_id');
    }

    public function journal(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'source_id')
            ->where('source_type', 'expense');
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
