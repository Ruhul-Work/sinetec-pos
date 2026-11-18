<?php

namespace App\Models\Sales;

use App\Models\backend\Account;
use App\Models\backend\PaymentType;
use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    protected $table = 'sale_payments';

    protected $fillable = [
        'payment_no',
        'sale_id',
        'customer_receipt_id',
        'payment_type_id',
        'account_id',
        'payment_at',
        'amount',
        'tendered_amount',
        'change_amount',
        'reference_no',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_at' => 'immutable_datetime',
            'amount' => 'decimal:4',
            'tendered_amount' => 'decimal:4',
            'change_amount' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customerReceipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class);
    }

    public function paymentType(): BelongsTo
    {
        return $this->belongsTo(PaymentType::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
