<?php
namespace App\Services\Reporting;

use App\Enums\Reporting\DemandPlanStatus;
use App\Enums\Reporting\ReportingPeriodStatus;
use App\Exceptions\Reporting\InvalidDemandPlanException;
use App\Models\backend\Branch;
use App\Models\backend\FinishedProduct;
use App\Models\Reporting\DemandPlan;
use App\Models\Reporting\ReportingPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DemandPlanService
{
    /**
     * Return one auditable sales-history suggestion for every active Finished Product.
     *
     * @return Collection<int, array{
     *     finished_product: FinishedProduct,
     *     historical_net_quantity: string,
     *     suggested_quantity: string
     * }>
     */
    public function suggestions(
        Branch $branch,
        ReportingPeriod $period,
        int $historyMonths = 3,
    ): Collection {
        $historyMonths = $this->validatedHistoryMonths($historyMonths);
        $historyStart  = $period->starts_on
            ->subMonthsNoOverflow($historyMonths)
            ->startOfMonth();
        $historyEnd = $period->starts_on->subDay()->endOfDay();

        $sold = DB::table('sale_items as item')
            ->join('sales as sale', 'sale.id', '=', 'item.sale_id')
            ->where('sale.status', 'POSTED')
            ->where('sale.branch_id', $branch->id)
            ->whereBetween('sale.sale_at', [$historyStart, $historyEnd])
            ->groupBy('item.finished_product_id')
            ->selectRaw('item.finished_product_id as product_id, SUM(item.base_quantity) as quantity')
            ->pluck('quantity', 'product_id');

        $returned = DB::table('sale_return_items as item')
            ->join('sale_returns as sale_return', 'sale_return.id', '=', 'item.sale_return_id')
            ->where('sale_return.status', 'POSTED')
            ->where('sale_return.branch_id', $branch->id)
            ->whereBetween('sale_return.return_at', [$historyStart, $historyEnd])
            ->groupBy('item.finished_product_id')
            ->selectRaw('item.finished_product_id as product_id, SUM(item.base_quantity) as quantity')
            ->pluck('quantity', 'product_id');

        return FinishedProduct::query()
            ->with('baseUnit:id,name,code,precision')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (FinishedProduct $product) use ($sold, $returned, $historyMonths): array {
                $soldQuantity      = (float) ($sold[$product->id] ?? 0);
                $returnedQuantity  = (float) ($returned[$product->id] ?? 0);
                $netQuantity       = max(0, $soldQuantity - $returnedQuantity);
                $suggestedQuantity = $netQuantity / $historyMonths;

                return [
                    'finished_product'        => $product,
                    'historical_net_quantity' => $this->quantity($netQuantity),
                    'suggested_quantity'      => $this->quantity($suggestedQuantity),
                ];
            });
    }

    /** @param array<int|string, array<string, mixed>> $items */
    public function createDraft(
        Branch $branch,
        ReportingPeriod $period,
        int $historyMonths,
        array $items,
        ?string $note,
        int $userId,
    ): DemandPlan {
        $historyMonths = $this->validatedHistoryMonths($historyMonths);

        return DB::transaction(function () use (
            $branch,
            $period,
            $historyMonths,
            $items,
            $note,
            $userId,
        ): DemandPlan {
            $branch = Branch::query()
                ->whereKey($branch->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $period = ReportingPeriod::query()
                ->whereKey($period->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOpenPeriod($period);
            $this->assertPlanDoesNotExist((int) $branch->id, (int) $period->id);

            $rows = $this->preparedItems($branch, $period, $historyMonths, $items);
            $plan = DemandPlan::query()->create([
                'plan_no'             => $this->nextPlanNumber(),
                'branch_id'           => $branch->id,
                'reporting_period_id' => $period->id,
                'history_months'      => $historyMonths,
                'status'              => DemandPlanStatus::Draft,
                'note'                => $this->text($note, 5000),
                'created_by'          => $userId,
            ]);

            $plan->items()->createMany($rows);

            return $plan->load($this->detailRelations());
        }, 3);
    }

    /** @param array<int|string, array<string, mixed>> $items */
    public function updateDraft(
        DemandPlan $plan,
        int $historyMonths,
        array $items,
        ?string $note,
    ): DemandPlan {
        $historyMonths = $this->validatedHistoryMonths($historyMonths);

        return DB::transaction(function () use ($plan, $historyMonths, $items, $note): DemandPlan {
            $plan = DemandPlan::query()
                ->with(['branch', 'period'])
                ->whereKey($plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($plan);
            $this->assertOpenPeriod($plan->period);

            $rows = $this->preparedItems(
                $plan->branch,
                $plan->period,
                $historyMonths,
                $items,
            );

            $plan->update([
                'history_months' => $historyMonths,
                'note'           => $this->text($note, 5000),
            ]);
            $plan->items()->delete();
            $plan->items()->createMany($rows);

            return $plan->fresh($this->detailRelations());
        }, 3);
    }

    public function approve(DemandPlan $plan, int $userId): DemandPlan
    {
        return DB::transaction(function () use ($plan, $userId): DemandPlan {
            $plan = DemandPlan::query()
                ->with(['period', 'items'])
                ->whereKey($plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($plan);
            $this->assertOpenPeriod($plan->period);

            if ($plan->items->isEmpty()) {
                throw new InvalidDemandPlanException('Add at least one Finished Product before approval.');
            }

            $plan->update([
                'status'      => DemandPlanStatus::Approved,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            return $plan->fresh($this->detailRelations());
        }, 3);
    }

    public function cancel(DemandPlan $plan): DemandPlan
    {
        return DB::transaction(function () use ($plan): DemandPlan {
            $plan = DemandPlan::query()
                ->whereKey($plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($plan->status === DemandPlanStatus::Cancelled) {
                throw new InvalidDemandPlanException('The Demand Plan is already Cancelled.');
            }
            if ($plan->materialRequirementPlan()->exists()) {
                throw new InvalidDemandPlanException(
                    'This Demand Plan already has a Material Requirement Plan and cannot be cancelled.',
                );
            }

            $plan->update([
                'status'      => DemandPlanStatus::Cancelled,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            return $plan->fresh($this->detailRelations());
        }, 3);
    }

    public function delete(DemandPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $plan = DemandPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if (! in_array($plan->status, [DemandPlanStatus::Draft, DemandPlanStatus::Cancelled], true)) {
                throw new InvalidDemandPlanException('Only a Draft or Cancelled Demand Plan can be deleted.');
            }
            if ($plan->materialRequirementPlan()->exists()) {
                throw new InvalidDemandPlanException(
                    'This Demand Plan is already used by Material Requirement Planning.',
                );
            }

            $plan->delete();
        }, 3);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $submittedItems
     * @return array<int, array<string, mixed>>
     */
    private function preparedItems(
        Branch $branch,
        ReportingPeriod $period,
        int $historyMonths,
        array $submittedItems,
    ): array {
        $suggestions = $this->suggestions($branch, $period, $historyMonths)
            ->keyBy(fn(array $row): int => (int) $row['finished_product']->id);
        $rows = [];

        foreach ($submittedItems as $productId => $submitted) {
            $productId  = (int) $productId;
            $suggestion = $suggestions->get($productId);
            if ($suggestion === null) {
                throw new InvalidDemandPlanException('One or more selected Finished Products are unavailable.');
            }

            $forecast = $this->nonNegativeQuantity($submitted['forecast_quantity'] ?? 0);
            $safety   = $this->nonNegativeQuantity($submitted['safety_quantity'] ?? 0);
            if ((float) $forecast === 0.0 && (float) $safety === 0.0) {
                continue;
            }

            $suggested = (string) $suggestion['suggested_quantity'];
            $rows[]    = [
                'finished_product_id'     => $productId,
                'historical_net_quantity' => $suggestion['historical_net_quantity'],
                'suggested_quantity'      => $suggested,
                'forecast_quantity'       => $forecast,
                'safety_quantity'         => $safety,
                'basis'                   => $this->text(
                    $submitted['basis'] ?? null,
                    1000,
                ) ?? ((float) $forecast === (float) $suggested
                        ? "Previous {$historyMonths}-month net-sales average"
                        : 'Manual planning adjustment'),
            ];
        }

        if ($rows === []) {
            throw new InvalidDemandPlanException(
                'Enter a planned or safety quantity for at least one Finished Product.',
            );
        }

        return $rows;
    }

    private function assertPlanDoesNotExist(int $branchId, int $periodId): void
    {
        if (DemandPlan::query()
            ->where('branch_id', $branchId)
            ->where('reporting_period_id', $periodId)
            ->exists()) {
            throw new InvalidDemandPlanException(
                'A Demand Plan already exists for this branch and target month.',
            );
        }
    }

    private function assertDraft(DemandPlan $plan): void
    {
        if ($plan->status !== DemandPlanStatus::Draft) {
            throw new InvalidDemandPlanException('Only a Draft Demand Plan can be changed.');
        }
    }

    private function assertOpenPeriod(ReportingPeriod $period): void
    {
        if ($period->status !== ReportingPeriodStatus::Open) {
            throw new InvalidDemandPlanException('Demand Planning requires an Open target reporting period.');
        }
    }

    private function validatedHistoryMonths(int $historyMonths): int
    {
        if (! in_array($historyMonths, [1, 3, 6, 12], true)) {
            throw new InvalidDemandPlanException('Sales history must cover 1, 3, 6 or 12 months.');
        }

        return $historyMonths;
    }

    private function nonNegativeQuantity(mixed $value): string
    {
        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidDemandPlanException('Planned and safety quantities cannot be negative.');
        }

        return $this->quantity((float) $value);
    }

    private function quantity(float $value): string
    {
        return number_format($value, 6, '.', '');
    }

    private function text(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function nextPlanNumber(): string
    {
        do {
            $number = 'DP-' . now()->format('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } while (DemandPlan::query()->where('plan_no', $number)->exists());

        return $number;
    }

    /** @return array<int, string> */
    private function detailRelations(): array
    {
        return [
            'branch',
            'period',
            'items.finishedProduct.baseUnit',
            'creator',
            'approver',
        ];
    }
}
