<?php

namespace App\Services\Reporting;

use App\Enums\Reporting\MonthlyRateSheetStatus;
use App\Enums\Reporting\ReportingPeriodStatus;
use App\Exceptions\Reporting\InvalidMonthlyRateSheetException;
use App\Models\backend\FinishedProduct;
use App\Models\backend\Part;
use App\Models\backend\RawMaterial;
use App\Models\Reporting\MonthlyFinishedProductRate;
use App\Models\Reporting\MonthlyPartRate;
use App\Models\Reporting\MonthlyRawMaterialRate;
use App\Models\Reporting\MonthlyRateSheet;
use App\Models\Reporting\ReportingPeriod;
use Illuminate\Support\Facades\DB;

final class MonthlyRateSheetService
{
    public function create(ReportingPeriod $period, int $userId): MonthlyRateSheet
    {
        if ($period->status !== ReportingPeriodStatus::Open) {
            throw new InvalidMonthlyRateSheetException('A Monthly Rate Sheet can be created only for an Open reporting period.');
        }

        return DB::transaction(function () use ($period, $userId): MonthlyRateSheet {
            $period = ReportingPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($period->status !== ReportingPeriodStatus::Open) {
                throw new InvalidMonthlyRateSheetException('The reporting period is no longer Open.');
            }
            if ($sheet = MonthlyRateSheet::query()->where('reporting_period_id', $period->id)->first()) {
                return $sheet->load(['period', 'rawMaterialRates.item', 'partRates.item', 'finishedProductRates.item']);
            }

            $sheet = MonthlyRateSheet::query()->create([
                'reporting_period_id' => $period->id,
                'sheet_no' => $this->nextSheetNumber(),
                'status' => MonthlyRateSheetStatus::Draft,
                'created_by' => $userId,
            ]);

            foreach (RawMaterial::query()->where('is_active', true)->orderBy('name')->get() as $item) {
                [$rate, $basis] = $this->suggestPurchaseRate('raw_material_id', $item->id, $period);
                MonthlyRawMaterialRate::query()->create([
                    'monthly_rate_sheet_id' => $sheet->id,
                    'raw_material_id' => $item->id,
                    'rate' => $rate,
                    'rate_basis' => $basis,
                ]);
            }

            foreach (Part::query()->where('is_active', true)->orderBy('name')->get() as $item) {
                [$rate, $basis] = $this->suggestPurchaseRate('part_id', $item->id, $period);
                MonthlyPartRate::query()->create([
                    'monthly_rate_sheet_id' => $sheet->id,
                    'part_id' => $item->id,
                    'rate' => $rate,
                    'rate_basis' => $basis,
                ]);
            }

            foreach (FinishedProduct::query()->where('is_active', true)->orderBy('name')->get() as $item) {
                $previous = MonthlyFinishedProductRate::query()
                    ->where('finished_product_id', $item->id)
                    ->whereHas('sheet', function ($query) use ($period): void {
                        $query->whereIn('status', [
                            MonthlyRateSheetStatus::Approved->value,
                            MonthlyRateSheetStatus::Locked->value,
                        ])->whereHas('period', function ($periodQuery) use ($period): void {
                            $periodQuery->where(function ($dateQuery) use ($period): void {
                                $dateQuery->where('year', '<', $period->year)
                                    ->orWhere(function ($monthQuery) use ($period): void {
                                        $monthQuery->where('year', $period->year)
                                            ->where('month', '<', $period->month);
                                    });
                            });
                        });
                    })
                    ->latest('id')
                    ->first();
                MonthlyFinishedProductRate::query()->create([
                    'monthly_rate_sheet_id' => $sheet->id,
                    'finished_product_id' => $item->id,
                    'rate' => $previous ? $previous->rate : $item->wholesale_price,
                    'rate_basis' => $previous ? 'PREVIOUS_APPROVED' : 'WHOLESALE_PRICE',
                ]);
            }

            return $sheet->load(['period', 'rawMaterialRates.item', 'partRates.item', 'finishedProductRates.item']);
        }, 3);
    }

    public function save(MonthlyRateSheet $sheet, array $data, int $userId): MonthlyRateSheet
    {
        return DB::transaction(function () use ($sheet, $data, $userId): MonthlyRateSheet {
            $sheet = MonthlyRateSheet::query()->with('period')->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($sheet);

            $this->replaceDomainRates($sheet, 'raw_material_id', 'rawMaterialRates', $data['raw_material_rates'] ?? []);
            $this->replaceDomainRates($sheet, 'part_id', 'partRates', $data['part_rates'] ?? []);
            $this->replaceDomainRates($sheet, 'finished_product_id', 'finishedProductRates', $data['finished_product_rates'] ?? []);

            $sheet->update([
                'note' => $this->text($data['note'] ?? null),
            ]);

            return $sheet->fresh(['period', 'rawMaterialRates.item', 'partRates.item', 'finishedProductRates.item']);
        }, 3);
    }

    public function approve(MonthlyRateSheet $sheet, int $userId): MonthlyRateSheet
    {
        return DB::transaction(function () use ($sheet, $userId): MonthlyRateSheet {
            $sheet = MonthlyRateSheet::query()->with('period')->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($sheet);
            if ($sheet->status !== MonthlyRateSheetStatus::Draft) {
                throw new InvalidMonthlyRateSheetException('Only a Draft Monthly Rate Sheet can be approved.');
            }

            $sheet->update([
                'status' => MonthlyRateSheetStatus::Approved,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            return $sheet->fresh(['period', 'rawMaterialRates.item', 'partRates.item', 'finishedProductRates.item']);
        }, 3);
    }

    public function lock(MonthlyRateSheet $sheet, int $userId): MonthlyRateSheet
    {
        return DB::transaction(function () use ($sheet, $userId): MonthlyRateSheet {
            $sheet = MonthlyRateSheet::query()->with('period')->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if ($sheet->period->status !== ReportingPeriodStatus::Open) {
                throw new InvalidMonthlyRateSheetException('A Monthly Rate Sheet can be locked only while its reporting period is Open.');
            }
            if ($sheet->status !== MonthlyRateSheetStatus::Approved) {
                throw new InvalidMonthlyRateSheetException('Only an Approved Monthly Rate Sheet can be locked.');
            }

            $sheet->update(['status' => MonthlyRateSheetStatus::Locked, 'locked_at' => now()]);

            return $sheet->fresh(['period', 'rawMaterialRates.item', 'partRates.item', 'finishedProductRates.item']);
        }, 3);
    }

    private function assertEditable(MonthlyRateSheet $sheet): void
    {
        if ($sheet->period->status !== ReportingPeriodStatus::Open) {
            throw new InvalidMonthlyRateSheetException('The reporting period is Closed. Rate changes require an authorised reopen.');
        }
        if ($sheet->status === MonthlyRateSheetStatus::Locked) {
            throw new InvalidMonthlyRateSheetException('A Locked Monthly Rate Sheet cannot be changed.');
        }
    }

    private function replaceDomainRates(MonthlyRateSheet $sheet, string $itemColumn, string $relation, array $rates): void
    {
        foreach ($rates as $itemId => $value) {
            $id = (int) $itemId;
            $rate = number_format((float) $value, 4, '.', '');
            if ($id < 1 || (float) $value < 0) {
                throw new InvalidMonthlyRateSheetException('Every Monthly Rate must be zero or greater.');
            }

            $sheet->{$relation}()
                ->where($itemColumn, $id)
                ->update([
                    'rate' => $rate,
                    'rate_basis' => 'MANUAL',
                ]);
        }
    }

    /** @return array{0: string, 1: string} */
    private function suggestPurchaseRate(string $itemColumn, int $itemId, ReportingPeriod $period): array
    {
        $row = DB::table('purchase_receipt_item as item')
            ->join('purchase_receipt as receipt', 'receipt.id', '=', 'item.purchase_receipt_id')
            ->where('receipt.status', 'POSTED')
            ->where('item.' . $itemColumn, $itemId)
            ->whereBetween('receipt.receipt_date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()])
            ->selectRaw('SUM(item.base_quantity) as quantity, SUM(item.base_quantity * (item.unit_rate / NULLIF(item.conversion_to_base, 0))) as value')
            ->first();

        if ($row && (float) $row->quantity > 0) {
            return [number_format((float) $row->value / (float) $row->quantity, 4, '.', ''), 'WEIGHTED_PURCHASE'];
        }

        return ['0.0000', 'MANUAL'];
    }

    private function nextSheetNumber(): string
    {
        do {
            $number = 'MRS-' . now()->format('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } while (MonthlyRateSheet::query()->where('sheet_no', $number)->exists());

        return $number;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, 5000);
    }
}
