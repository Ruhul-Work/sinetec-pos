<?php
namespace App\Models\Reporting;

use App\Models\backend\FinishedProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyFinishedProductRate extends Model
{
    protected $fillable = ['monthly_rate_sheet_id', 'finished_product_id', 'rate', 'rate_basis'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:4'];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(MonthlyRateSheet::class, 'monthly_rate_sheet_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinishedProduct::class, 'finished_product_id');
    }
}
