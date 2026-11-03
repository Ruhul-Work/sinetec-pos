<?php

namespace App\Models\backend;

use App\Models\Finance\FinanceAccountMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $table = 'accounts';

    protected $fillable = [
        'account_type_id', 'parent_id', 'name', 'code', 'description', 'currency',
        'bank_name', 'bank_account_no', 'bank_details', 'allow_negative', 'is_active', 'is_system',
    ];

    protected function casts(): array
    {
        return [
            'allow_negative' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'account_type_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function branchAccounts(): HasMany
    {
        return $this->hasMany(BranchAccount::class, 'account_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }

    public function financeMappings(): HasMany
    {
        return $this->hasMany(FinanceAccountMapping::class, 'account_id');
    }
}
