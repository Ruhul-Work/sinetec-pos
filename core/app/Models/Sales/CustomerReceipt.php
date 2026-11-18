<?php

namespace App\Models\Sales;

use App\Models\backend\Branch;
use App\Models\backend\Customer;
use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerReceipt extends Model
{
    protected $fillable = [
        'receipt_no',
        'branch_id',
        'customer_id',
        'sale_id',
        'receipt_at',
        'amount',
        'due_before',
        'due_after',
        'status',
        'note',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'receipt_at' => 'immutable_datetime',
            'amount' => 'decimal:4',
            'due_before' => 'decimal:4',
            'due_after' => 'decimal:4',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
