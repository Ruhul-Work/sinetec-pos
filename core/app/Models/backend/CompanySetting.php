<?php

namespace App\Models\backend;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $table = 'company_settings';

    protected $fillable = [
        'name',
        'code',
        'logo',
        'address',
        'city',
        'country',
        'email',
        'phone',
        'website',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
