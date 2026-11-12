<?php

namespace App\Models\Production;

use App\Enums\Production\PartProductionStatus;
use App\Enums\Production\ProductionQcStatus;
use App\Models\backend\Branch;
use App\Models\backend\JournalEntry;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use App\Models\Finance\Expense;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PartProductionBatch extends Model
{
    protected $fillable = [
        'batch_no',
        'part_bom_id',
        'branch_id',
        'input_warehouse_id',
        'output_warehouse_id',
        'production_date',
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
            'production_date' => 'date',
            'planned_quantity' => 'decimal:6',
            'good_quantity' => 'decimal:6',
            'reject_quantity' => 'decimal:6',
            'qc_status' => ProductionQcStatus::class,
            'status' => PartProductionStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(PartBom::class, 'part_bom_id');
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
        return $this->hasMany(PartProductionConsumption::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(PartProductionExpense::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function journal(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'source_id')
            ->where('source_type', 'part_production_batch');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Expense::class, 'part_production_batch_id');
    }
}
