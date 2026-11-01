<?php

namespace App\Models\Purchase;

use App\Enums\Purchase\PurchaseReturnStatus;
use App\Models\backend\Branch;
use App\Models\backend\Supplier;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReturn extends Model
{
    protected $table = 'purchase_return';

    protected $fillable = [
        'return_no', 'purchase_receipt_id', 'branch_id', 'supplier_id', 'warehouse_id',
        'return_date', 'status', 'total', 'reason', 'created_by', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'immutable_date',
            'status' => PurchaseReturnStatus::class,
            'total' => 'decimal:4',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }
}
