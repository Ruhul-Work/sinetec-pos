<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\StockTransferStatus;
use App\Models\backend\Branch;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $fillable = [
        'transfer_no', 'branch_id', 'source_warehouse_id', 'destination_warehouse_id',
        'transfer_at', 'status', 'note', 'created_by', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'transfer_at' => 'immutable_datetime',
            'status' => StockTransferStatus::class,
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function rawMaterialItems(): HasMany
    {
        return $this->hasMany(RawMaterialTransferItem::class);
    }

    public function partItems(): HasMany
    {
        return $this->hasMany(PartTransferItem::class);
    }

    public function finishedProductItems(): HasMany
    {
        return $this->hasMany(FinishedProductTransferItem::class);
    }
}
