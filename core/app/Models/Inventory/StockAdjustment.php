<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\StockAdjustmentStatus;
use App\Models\backend\Branch;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAdjustment extends Model
{
    protected $fillable = [
        'adjustment_no',
        'branch_id',
        'warehouse_id',
        'adjustment_at',
        'adjustment_type',
        'status',
        'reason',
        'note',
        'created_by',
        'approved_by',
        'posted_by',
        'approved_at',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_at' => 'immutable_datetime',
            'status' => StockAdjustmentStatus::class,
            'approved_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function rawMaterialItems(): HasMany
    {
        return $this->hasMany(RawMaterialAdjustmentItem::class);
    }

    public function partItems(): HasMany
    {
        return $this->hasMany(PartAdjustmentItem::class);
    }

    public function finishedProductItems(): HasMany
    {
        return $this->hasMany(FinishedProductAdjustmentItem::class);
    }
}
