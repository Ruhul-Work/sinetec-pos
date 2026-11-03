<?php

namespace App\Models\backend;

use App\Enums\Finance\JournalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JournalEntry extends Model
{
    protected $table = 'journal_entries';

    protected $fillable = [
        'voucher_no', 'voucher_type_id', 'branch_id', 'fiscal_year_id',
        'source_type', 'source_id', 'posting_key', 'entry_date', 'status', 'narration',
        'created_by', 'approved_by', 'approved_at', 'posted_by', 'posted_at',
        'voided_by', 'voided_at', 'void_reason', 'reversal_of_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'immutable_date',
            'source_id' => 'integer',
            'status' => JournalStatus::class,
            'approved_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function voucherType(): BelongsTo
    {
        return $this->belongsTo(VoucherType::class, 'voucher_type_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
