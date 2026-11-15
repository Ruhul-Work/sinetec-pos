<?php

namespace App\Models\Production;

use App\Enums\Production\ProductAssemblyStatus;
use App\Enums\Production\ProductionQcStatus;
use App\Models\backend\Branch;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductAssemblyBatch extends Model
{
    protected $fillable = [
        'batch_no',
        'product_bom_id',
        'branch_id',
        'input_warehouse_id',
        'output_warehouse_id',
        'assembly_date',
        'planned_quantity',
        'good_quantity',
        'reject_quantity',
        'qc_status',
        'status',
        'qc_note',
        'created_by',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'assembly_date' => 'date',
            'planned_quantity' => 'decimal:6',
            'good_quantity' => 'decimal:6',
            'reject_quantity' => 'decimal:6',
            'qc_status' => ProductionQcStatus::class,
            'status' => ProductAssemblyStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(ProductBom::class, 'product_bom_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function inputWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'input_warehouse_id');
    }

    public function outputWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'output_warehouse_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ProductAssemblyConsumption::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ProductAssemblyExpense::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
