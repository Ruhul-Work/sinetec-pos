<?php

namespace App\Models\Production;

use App\Models\backend\Part;
use App\Models\backend\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartBom extends Model
{
    protected $fillable = [
        'part_id',
        'version',
        'output_quantity',
        'status',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected $casts = [
        'output_quantity' => 'decimal:6',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PartBomItem::class);
    }

    public function productionBatches(): HasMany
    {
        return $this->hasMany(PartProductionBatch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
