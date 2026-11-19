<?php

namespace App\Models\Sales;

use App\Models\backend\Account;
use App\Models\backend\PaymentType;
use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnPayment extends Model
{
    protected $table = 'sale_return_payments';

    protected $fillable = [
        'sale_return_id',
        'payment_type_id',
        'account_id',
        'amount',
        'reference_no',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
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
