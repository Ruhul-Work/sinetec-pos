<?php

namespace App\Models\backend;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    //
    protected $table = 'districts';

    protected $primaryKey = 'district_id';

    protected $fillable = [
        'district_division_id',
        'district_name',
        'district_bn_name',
        'district_lat',
        'district_lon',
        'district_url',
    ];

    public function district_division(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'district_division_id');
    }

    public function district_upazila(): HasMany
    {
        return $this->hasMany(Upazila::class, 'upazila_district_id', 'district_id');
    }
}
