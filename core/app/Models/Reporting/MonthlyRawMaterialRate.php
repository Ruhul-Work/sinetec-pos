<?php
namespace App\Models\Reporting;

use App\Models\backend\RawMaterial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyRawMaterialRate extends Model
{
    protected $fillable = ['monthly_rate_sheet_id', 'raw_material_id', 'rate', 'rate_basis'];

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
        return $this->belongsTo(RawMaterial::class, 'raw_material_id');
    }
}
