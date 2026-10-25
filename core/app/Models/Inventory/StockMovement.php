<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use App\Models\backend\Branch;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

abstract class StockMovement extends Model
{
    protected $fillable = [
        'branch_id',
        'warehouse_id',
        'movement_at',
        'movement_type',
        'direction',
        'quantity',
        'unit_cost',
        'source_type',
        'source_id',
        'source_item_id',
        'posting_key',
        'reversal_of_id',
        'note',
        'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'movement_at' => 'immutable_datetime',
            'movement_type' => StockMovementType::class,
            'direction' => StockDirection::class,
            'quantity' => 'decimal:6',
            'unit_cost' => 'decimal:4',
            'source_id' => 'integer',
            'source_item_id' => 'integer',
            'reversal_of_id' => 'integer',
            'posted_by' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(static::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(static::class, 'reversal_of_id');
    }
}
