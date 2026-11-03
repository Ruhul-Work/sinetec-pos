<?php

namespace App\Models\Purchase;

use App\Enums\Purchase\PurchasePaymentStatus;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\PaymentType;
use App\Models\backend\Supplier;
use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchasePayment extends Model
{
    protected $table = 'purchase_payment';

    protected $fillable = ['payment_no', 'branch_id', 'supplier_id', 'purchase_receipt_id', 'payment_type_id', 'account_id', 'payment_date', 'amount', 'reference_no', 'status', 'note', 'created_by', 'posted_by', 'posted_at'];

    protected function casts(): array
    {
        return ['payment_date' => 'immutable_date', 'amount' => 'decimal:4', 'status' => PurchasePaymentStatus::class, 'posted_at' => 'immutable_datetime'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function paymentType(): BelongsTo
    {
        return $this->belongsTo(PaymentType::class);
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
