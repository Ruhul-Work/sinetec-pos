<?php

namespace App\Models\backend;

use Illuminate\Database\Eloquent\Model;

class LoyaltyRule extends Model
{
    protected $table = 'loyalty_rules';
    protected $fillable = [
        'name',
        'earn_amount',
        'earn_points',
        'redeem_points',
        'redeem_amount',
        'min_redeem_points',
        'max_redeem_points',
        'is_active',
    ];

    protected $casts = [
        'earn_amount' => 'decimal:2',
        'redeem_amount' => 'decimal:2',
        'earn_points' => 'integer',
        'redeem_points' => 'integer',
        'min_redeem_points' => 'integer',
        'max_redeem_points' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    public function scopeLatestActive($query)
    {
        return $query->active()->orderByDesc('updated_at')->orderByDesc('id');
    }
}
