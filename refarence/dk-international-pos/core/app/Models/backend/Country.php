<?php

namespace App\Models\backend;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $table = 'countries';

    protected $fillable = [
        'name',
    ];

    public function divisions(): HasMany
    {
        return $this->hasMany(Division::class, 'country_id');
    }
}
