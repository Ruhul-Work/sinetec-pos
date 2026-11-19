<?php

namespace App\Models\Sales;

use App\Enums\Sales\SaleReturnStatus;
use App\Models\backend\Branch;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    protected $fillable = [
        'return_no',
        'sale_id',
        'branch_id',
        'warehouse_id',
        'return_at',
        'status',
        'subtotal',
        'discount',
        'tax',
        'total',
        'due_adjustment',
        'refund_amount',
        'reason',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'return_at' => 'immutable_datetime',
            'status' => SaleReturnStatus::class,
            'subtotal' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'total' => 'decimal:4',
            'due_adjustment' => 'decimal:4',
            'refund_amount' => 'decimal:4',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SaleReturnPayment::class);
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
