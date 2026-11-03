<?php

namespace App\Models\Finance;

use App\Enums\Finance\FinanceMappingKey;
use App\Enums\Finance\FinanceMappingScope;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceAccountMapping extends Model
{
    protected $table = 'finance_account_mappings';

    protected $fillable = [
        'scope', 'scope_key', 'branch_id', 'mapping_key', 'account_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'scope' => FinanceMappingScope::class,
            'mapping_key' => FinanceMappingKey::class,
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
