<?php

namespace App\Models\Purchase;

use App\Enums\Purchase\PurchaseReceiptStatus;
use App\Models\backend\Branch;
use App\Models\backend\Supplier;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReceipt extends Model
{
    protected $table = 'purchase_receipt';

    protected $fillable = [
        'receipt_no', 'purchase_order_id', 'branch_id', 'supplier_id', 'warehouse_id',
        'receipt_date', 'supplier_invoice_no', 'supplier_invoice_path', 'status', 'subtotal', 'discount_type',
        'discount_value', 'discount', 'tax', 'total', 'note', 'created_by', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'immutable_date',
            'status' => PurchaseReceiptStatus::class,
            'subtotal' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'total' => 'decimal:4',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
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
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }
}
