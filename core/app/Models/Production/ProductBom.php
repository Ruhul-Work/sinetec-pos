<?php

namespace App\Models\Production;

use App\Models\backend\FinishedProduct;
use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBom extends Model
{
    protected $fillable = [
        'finished_product_id',
        'version',
        'output_quantity',
        'status',
        'effective_from',
        'effective_to',
        'validity_updated_by',
        'validity_updated_at',
        'created_by',
    ];

    protected $casts = [
        'output_quantity' => 'decimal:6',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'validity_updated_at' => 'datetime',
    ];

    public function finishedProduct(): BelongsTo
    {
        return $this->belongsTo(FinishedProduct::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductBomItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validityUpdater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validity_updated_by');
    }

    public function isExpired(): bool
    {
        return $this->effective_to?->isBefore(today()) ?? false;
    }

    public function isScheduled(): bool
    {
        return $this->effective_from?->isAfter(today()) ?? false;
    }
}
