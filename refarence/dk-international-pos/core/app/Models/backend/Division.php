<?php

namespace App\Models\backend;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Division extends Model
{
    //
    protected $table = 'divisions';

    protected $fillable = [
        'country_id',
        'name',
        'bn_name',
        'url',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function division_district(): HasMany
    {
        return $this->hasMany(District::class, 'district_division_id');
    }
}
