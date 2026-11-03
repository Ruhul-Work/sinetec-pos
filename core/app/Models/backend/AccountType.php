<?php

namespace App\Models\backend;

use App\Enums\Finance\AccountNormalBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountType extends Model
{
    protected $fillable = ['name', 'code', 'normal_balance', 'is_system'];

    protected function casts(): array
    {
        return [
            'normal_balance' => AccountNormalBalance::class,
            'is_system' => 'boolean',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'account_type_id');
    }
}
