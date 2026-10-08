<?php

namespace App\Models\backend;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $table = 'accounts';

    protected $fillable = [
        'name',
        'account_type_id',
        'description',
        'currency',
        'bank_name',
        'bank_account_no',
        'bank_details',
        'allow_negative',
        'is_active',
    ];

    protected $casts = [
        'allow_negative' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function branchAccounts()
    {
        return $this->hasMany(BranchAccount::class, 'account_id');
    }

    public function type()
    {
        return $this->belongsTo(AccountType::class, 'account_type_id');
    }
}
