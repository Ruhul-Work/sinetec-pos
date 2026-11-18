<?php

namespace App\Models\Sales;

use App\Enums\Sales\SaleChannel;
use App\Enums\Sales\SaleStatus;
use App\Models\backend\Branch;
use App\Models\backend\Customer;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    protected $fillable = [
        'sale_no',
        'branch_id',
        'warehouse_id',
        'customer_id',
        'customer_reference',
        'channel',
        'sale_at',
        'due_date',
        'status',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount',
        'tax',
        'total',
        'paid_amount',
        'due_amount',
        'note',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => SaleChannel::class,
            'sale_at' => 'immutable_datetime',
            'due_date' => 'immutable_date',
            'status' => SaleStatus::class,
            'subtotal' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'total' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'due_amount' => 'decimal:4',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function customerReceipts(): HasMany
    {
        return $this->hasMany(CustomerReceipt::class);
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
