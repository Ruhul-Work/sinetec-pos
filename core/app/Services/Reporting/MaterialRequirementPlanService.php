<?php
namespace App\Services\Reporting;

use App\Enums\Purchase\PurchaseOrderStatus;
use App\Enums\Reporting\DemandPlanStatus;
use App\Enums\Reporting\MrpStatus;
use App\Exceptions\Reporting\InvalidMrpException;
use App\Models\Reporting\DemandPlan;
use App\Models\Reporting\MaterialRequirementPlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class MaterialRequirementPlanService
{
    public function __construct(
        private readonly MaterialRequirementPurchaseRecommendationService $purchaseRecommendations,
    ) {}

    /**
     * Generate one planning snapshot from an Approved Demand Plan.
     *
     * The target period's opening date resolves BOMs. Branch stock, warehouse
     * safety levels and open incoming Purchase Orders are then offset.
     */
    public function generate(
        DemandPlan $demandPlan,
        int $userId,
    ): MaterialRequirementPlan {
        return DB::transaction(function () use ($demandPlan, $userId): MaterialRequirementPlan {
            $demandPlan = DemandPlan::query()
                ->with([
                    'branch',
                    'period',
                    'items.finishedProduct.baseUnit',
                ])
                ->whereKey($demandPlan->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertApprovedDemandPlan($demandPlan);
            $this->assertNoExistingMrp($demandPlan);

            $branchId              = (int) $demandPlan->branch_id;
            $finishedProductResult = $this->finishedProductRequirements(
                $demandPlan,
                $branchId,
            );
            $componentRequirements = $this->explodeProductBoms(
                $finishedProductResult['net_demand'],
                $demandPlan->period->starts_on,
            );
            $componentLines = $this->componentRequirements(
                $componentRequirements,
                $branchId,
                $demandPlan->period->ends_on,
            );

            $plan = MaterialRequirementPlan::query()->create([
                'plan_no'             => $this->nextPlanNumber(),
                'demand_plan_id'      => $demandPlan->id,
                'branch_id'           => $branchId,
                'reporting_period_id' => $demandPlan->reporting_period_id,
                'status'              => MrpStatus::Draft,
                'generated_by'        => $userId,
            ]);

            $plan->finishedProductLines()->createMany(
                $finishedProductResult['lines'],
            );
            $plan->componentLines()->createMany($componentLines);

            return $plan->load($this->detailRelations());
        }, 3);
    }

    public function approve(
        MaterialRequirementPlan $plan,
        int $userId,
    ): MaterialRequirementPlan {
        return DB::transaction(function () use ($plan, $userId): MaterialRequirementPlan {
            $plan = $this->lockPlan($plan);
            $this->assertDraft($plan);

            $plan->update([
                'status'      => MrpStatus::Approved,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            return $plan->fresh($this->detailRelations());
        }, 3);
    }

    public function cancel(
        MaterialRequirementPlan $plan,
    ): MaterialRequirementPlan {
        return DB::transaction(function () use ($plan): MaterialRequirementPlan {
            $plan = $this->lockPlan($plan);

            if ($plan->status === MrpStatus::Cancelled) {
                throw new InvalidMrpException(
                    'The Material Requirement Plan is already Cancelled.',
                );
            }

            $this->assertNoActivePurchaseOrders($plan);

            $plan->update([
                'status'      => MrpStatus::Cancelled,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            return $plan->fresh($this->detailRelations());
        }, 3);
    }

    public function delete(MaterialRequirementPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $plan = $this->lockPlan($plan);

            if (! in_array($plan->status, [MrpStatus::Draft, MrpStatus::Cancelled], true)) {
                throw new InvalidMrpException(
                    'Only a Draft or Cancelled MRP can be deleted.',
                );
            }

            if ($plan->purchaseOrders()->exists()) {
                throw new InvalidMrpException(
                    'This MRP has linked Purchase Orders and cannot be deleted.',
                );
            }

            $plan->delete();
        }, 3);
    }

    /** @return array<string, mixed> */
    public function purchaseRecommendations(MaterialRequirementPlan $plan): array
    {
        return $this->purchaseRecommendations->forPlan($plan);
    }

    /**
     * @return array{
     *     lines: array<int, array<string, int|string>>,
     *     net_demand: array<int, float>
     * }
     */
    private function finishedProductRequirements(
        DemandPlan $demandPlan,
        int $branchId,
    ): array {
        $stock = $this->stockByItem(
            'finished_product_stock_balances',
            'finished_product_id',
            $branchId,
        );
        $lines     = [];
        $netDemand = [];

        foreach ($demandPlan->items as $item) {
            $demand      = (float) $item->forecast_quantity;
            $safety      = (float) $item->safety_quantity;
            $available   = (float) ($stock[$item->finished_product_id] ?? 0);
            $netRequired = max(0, $demand + $safety - $available);

            $lines[] = [
                'finished_product_id' => $item->finished_product_id,
                'demand_quantity'     => $this->quantity($demand),
                'safety_quantity'     => $this->quantity($safety),
                'available_stock'     => $this->quantity($available),
                'net_required'        => $this->quantity($netRequired),
            ];

            if ($netRequired > 0) {
                $netDemand[(int) $item->finished_product_id] = $netRequired;
            }
        }

        return [
            'lines'      => $lines,
            'net_demand' => $netDemand,
        ];
    }

    /**
     * Resolve the BOM effective on the target period's first day and explode it.
     * Parts remain Part requirements; Part BOM explosion belongs to Part planning.
     *
     * @param  array<int, float>  $netFinishedProductDemand
     * @return array<string, float>
     */
    private function explodeProductBoms(
        array $netFinishedProductDemand,
        CarbonInterface $planningDate,
    ): array {
        if ($netFinishedProductDemand === []) {
            return [];
        }

        $productIds = array_keys($netFinishedProductDemand);
        $bomRows    = DB::table('product_boms')
            ->where('status', 'ACTIVE')
            ->where(function ($query) use ($planningDate): void {
                $query->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $planningDate->toDateString());
            })
            ->where(function ($query) use ($planningDate): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $planningDate->toDateString());
            })
            ->whereIn('finished_product_id', $productIds)
            ->orderBy('finished_product_id')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get(['id', 'finished_product_id', 'output_quantity']);

        $bomByProduct = [];
        foreach ($bomRows as $bom) {
            $productId = (int) $bom->finished_product_id;
            $bomByProduct[$productId] ??= $bom;
        }

        $missingProductIds = array_values(array_diff(
            $productIds,
            array_keys($bomByProduct),
        ));
        if ($missingProductIds !== []) {
            $this->throwMissingBomException($missingProductIds, $planningDate);
        }

        $bomIds = array_map(
            static fn(object $bom): int => (int) $bom->id,
            $bomByProduct,
        );
        $itemsByBom = DB::table('product_bom_items')
            ->whereIn('product_bom_id', $bomIds)
            ->orderBy('id')
            ->get()
            ->groupBy('product_bom_id');
        $requirements = [];

        foreach ($bomByProduct as $productId => $bom) {
            $bomItems = $itemsByBom->get((int) $bom->id, collect());
            if ($bomItems->isEmpty()) {
                throw new InvalidMrpException(
                    'The active Product BOM for Finished Product ID ' . $productId
                    . ' has no component lines.',
                );
            }

            $outputQuantity = max((float) $bom->output_quantity, 1);
            $runs           = ($netFinishedProductDemand[$productId] ?? 0) / $outputQuantity;

            foreach ($bomItems as $bomItem) {
                [$componentType, $componentId] = $this->bomComponent($bomItem);
                $baseQuantity                  = (float) $bomItem->base_quantity;
                $wastagePercent                = (float) ($bomItem->wastage_percent ?? 0);
                $requiredQuantity              = $baseQuantity
                     * (1 + ($wastagePercent / 100))
                     * $runs;
                $componentKey = $componentType . '|' . $componentId;

                $requirements[$componentKey] = (
                    $requirements[$componentKey] ?? 0
                ) + $requiredQuantity;
            }
        }

        return $requirements;
    }

    /**
     * @param  array<string, float>  $grossRequirements
     * @return array<int, array<string, int|string|null>>
     */
    private function componentRequirements(
        array $grossRequirements,
        int $branchId,
        CarbonInterface $planningPeriodEnd,
    ): array {
        if ($grossRequirements === []) {
            return [];
        }

        $partIds = [];
        foreach (array_keys($grossRequirements) as $key) {
            [$componentType, $componentId] = explode('|', $key);
            if ($componentType === 'PART') {
                $partIds[] = (int) $componentId;
            }
        }

        $rawMaterialStock = $this->stockByItem(
            'raw_material_stock_balances',
            'raw_material_id',
            $branchId,
        );
        $partStock = $this->stockByItem(
            'part_stock_balances',
            'part_id',
            $branchId,
        );
        $rawMaterialSafety = $this->reorderSafetyByItem(
            'raw_material_reorder_levels',
            'raw_material_id',
            $branchId,
        );
        $partSafety = $this->reorderSafetyByItem(
            'part_reorder_levels',
            'part_id',
            $branchId,
        );
        $rawMaterialIncoming = $this->incomingPurchaseByItem(
            'raw_material_id',
            $branchId,
            $planningPeriodEnd,
        );
        $partIncoming = $this->incomingPurchaseByItem(
            'part_id',
            $branchId,
            $planningPeriodEnd,
        );
        $partModes = DB::table('parts')
            ->whereIn('id', $partIds)
            ->pluck('procurement_mode', 'id')
            ->map(static fn(mixed $mode): string => strtoupper((string) $mode))
            ->all();
        $partsWithSuppliers = $this->partsWithActiveSuppliers($partIds);

        $lines = [];
        foreach ($grossRequirements as $key => $grossRequirement) {
            [$componentType, $componentIdValue] = explode('|', $key);
            $componentId                        = (int) $componentIdValue;
            $isRawMaterial                      = $componentType === 'RAW_MATERIAL';
            $available                          = $isRawMaterial
                ? (float) ($rawMaterialStock[$componentId] ?? 0)
                : (float) ($partStock[$componentId] ?? 0);
            $safety = $isRawMaterial
                ? (float) ($rawMaterialSafety[$componentId] ?? 0)
                : (float) ($partSafety[$componentId] ?? 0);
            $incoming = $isRawMaterial
                ? (float) ($rawMaterialIncoming[$componentId] ?? 0)
                : (float) ($partIncoming[$componentId] ?? 0);
            $netRequirement = max(
                0,
                $grossRequirement + $safety - $available - $incoming,
            );
            $sourcePlan = $this->componentSourcePlan(
                $isRawMaterial,
                $componentId,
                $netRequirement,
                (string) ($partModes[$componentId] ?? ''),
                isset($partsWithSuppliers[$componentId]),
                $incoming,
            );

            $lines[] = [
                'component_type'    => $componentType,
                'component_key'     => $componentType . ':' . $componentId,
                'raw_material_id'   => $isRawMaterial ? $componentId : null,
                'part_id'           => $isRawMaterial ? null : $componentId,
                'gross_requirement' => $this->quantity($grossRequirement),
                'available_stock'   => $this->quantity($available),
                'safety_quantity'   => $this->quantity($safety),
                'incoming_quantity' => $this->quantity($incoming),
                'net_requirement'   => $this->quantity($netRequirement),
                'make_quantity'     => $this->quantity($sourcePlan['make']),
                'buy_quantity'      => $this->quantity($sourcePlan['buy']),
                'source_hint'       => $sourcePlan['source'],
            ];
        }

        return $lines;
    }

    /** @return array{source: string, make: float, buy: float} */
    private function componentSourcePlan(
        bool $isRawMaterial,
        int $componentId,
        float $netRequirement,
        string $procurementMode,
        bool $hasSupplier,
        float $incomingQuantity,
    ): array {
        if ($isRawMaterial) {
            return [
                'source' => 'PURCHASE',
                'make'   => 0,
                'buy'    => $netRequirement,
            ];
        }

        if (! in_array($procurementMode, ['MAKE', 'BUY', 'BOTH'], true)) {
            throw new InvalidMrpException(
                'Part ID ' . $componentId . ' has an invalid procurement mode.',
            );
        }

        if ($procurementMode === 'MAKE') {
            return [
                'source' => 'PRODUCTION',
                'make'   => $netRequirement,
                'buy'    => 0,
            ];
        }

        if ($procurementMode === 'BUY') {
            return [
                'source' => 'PURCHASE',
                'make'   => 0,
                'buy'    => $netRequirement,
            ];
        }

        // BOTH defaults to Purchase when a supplier or an open Purchase Order
        // exists; otherwise the operational recommendation is internal Make.
        $usePurchase = $hasSupplier || $incomingQuantity > 0;

        return [
            'source' => $usePurchase ? 'PURCHASE' : 'PRODUCTION',
            'make'   => $usePurchase ? 0 : $netRequirement,
            'buy'    => $usePurchase ? $netRequirement : 0,
        ];
    }

    /** @return array<int, float> */
    private function stockByItem(
        string $balanceTable,
        string $itemColumn,
        int $branchId,
    ): array {
        return DB::table($balanceTable . ' as balance')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'balance.warehouse_id')
            ->where('warehouse.branch_id', $branchId)
            ->whereNull('warehouse.deleted_at')
            ->where('warehouse.is_active', true)
            ->groupBy('balance.' . $itemColumn)
            ->selectRaw('balance.' . $itemColumn . ' as item_id, SUM(balance.quantity) as quantity')
            ->pluck('quantity', 'item_id')
            ->map(static fn(mixed $quantity): float => (float) $quantity)
            ->all();
    }

    /** @return array<int, float> */
    private function reorderSafetyByItem(
        string $reorderTable,
        string $itemColumn,
        int $branchId,
    ): array {
        return DB::table($reorderTable . ' as reorder_level')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'reorder_level.warehouse_id')
            ->where('warehouse.branch_id', $branchId)
            ->whereNull('warehouse.deleted_at')
            ->where('warehouse.is_active', true)
            ->groupBy('reorder_level.' . $itemColumn)
            ->selectRaw('reorder_level.' . $itemColumn . ' as item_id, SUM(reorder_level.minimum_quantity) as quantity')
            ->pluck('quantity', 'item_id')
            ->map(static fn(mixed $quantity): float => (float) $quantity)
            ->all();
    }

    /** @return array<int, float> */
    private function incomingPurchaseByItem(
        string $itemColumn,
        int $branchId,
        CarbonInterface $planningPeriodEnd,
    ): array {
        $incoming = [];
        $rows     = DB::table('purchase_order_item as item')
            ->join('purchase_order as purchase_order', 'purchase_order.id', '=', 'item.purchase_order_id')
            ->where('purchase_order.branch_id', $branchId)
            ->whereIn('purchase_order.status', [
                PurchaseOrderStatus::Approved->value,
                PurchaseOrderStatus::Partial->value,
            ])
            ->where(function ($query) use ($planningPeriodEnd): void {
                $query->whereNull('purchase_order.expected_date')
                    ->orWhereDate(
                        'purchase_order.expected_date',
                        '<=',
                        $planningPeriodEnd->toDateString(),
                    );
            })
            ->whereNotNull('item.' . $itemColumn)
            ->get([
                'item.' . $itemColumn . ' as item_id',
                'item.base_quantity',
                'item.received_base_quantity',
            ]);

        foreach ($rows as $row) {
            $pending = max(
                0,
                (float) $row->base_quantity - (float) $row->received_base_quantity,
            );
            $incoming[(int) $row->item_id] = (
                $incoming[(int) $row->item_id] ?? 0
            ) + $pending;
        }

        return $incoming;
    }

    /** @param array<int, int> $partIds @return array<int, bool> */
    private function partsWithActiveSuppliers(array $partIds): array
    {
        if ($partIds === []) {
            return [];
        }

        return DB::table('part_suppliers as supplier_map')
            ->join('suppliers as supplier', 'supplier.id', '=', 'supplier_map.supplier_id')
            ->whereIn('supplier_map.part_id', $partIds)
            ->whereNull('supplier.deleted_at')
            ->where('supplier.is_active', true)
            ->distinct()
            ->pluck('supplier_map.part_id')
            ->mapWithKeys(static fn(mixed $partId): array=> [(int) $partId => true])
            ->all();
    }

    /** @return array{0: string, 1: int} */
    private function bomComponent(object $bomItem): array
    {
        if ($bomItem->raw_material_id !== null && $bomItem->part_id === null) {
            return ['RAW_MATERIAL', (int) $bomItem->raw_material_id];
        }

        if ($bomItem->part_id !== null && $bomItem->raw_material_id === null) {
            return ['PART', (int) $bomItem->part_id];
        }

        throw new InvalidMrpException(
            'A Product BOM component must reference exactly one Raw Material or Part.',
        );
    }

    /** @param array<int, int> $productIds */
    private function throwMissingBomException(
        array $productIds,
        CarbonInterface $planningDate,
    ): never {
        $products = DB::table('finished_products')
            ->whereIn('id', $productIds)
            ->orderBy('name')
            ->get(['name', 'product_code'])
            ->map(static fn(object $product): string => $product->name . ' (' . $product->product_code . ')')
            ->implode(', ');

        throw new InvalidMrpException(
            'MRP cannot be generated because no active Product BOM is valid on '
            . $planningDate->format('d M Y') . ' for: ' . $products . '.',
        );
    }

    private function assertApprovedDemandPlan(DemandPlan $plan): void
    {
        if ($plan->status !== DemandPlanStatus::Approved) {
            throw new InvalidMrpException(
                'MRP can only be generated from an Approved Demand Plan.',
            );
        }
    }

    private function assertNoExistingMrp(DemandPlan $plan): void
    {
        if ($plan->materialRequirementPlan()->exists()) {
            throw new InvalidMrpException(
                'A Material Requirement Plan already exists for this Demand Plan.',
            );
        }
    }

    private function assertDraft(MaterialRequirementPlan $plan): void
    {
        if ($plan->status !== MrpStatus::Draft) {
            throw new InvalidMrpException('Only a Draft MRP can be changed.');
        }
    }

    private function assertNoActivePurchaseOrders(
        MaterialRequirementPlan $plan,
    ): void {
        $hasActiveOrder = $plan->purchaseOrders()
            ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->exists();

        if ($hasActiveOrder) {
            throw new InvalidMrpException(
                'Cancel linked Purchase Orders before cancelling this MRP.',
            );
        }
    }

    private function lockPlan(
        MaterialRequirementPlan $plan,
    ): MaterialRequirementPlan {
        return MaterialRequirementPlan::query()
            ->whereKey($plan->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function quantity(float $value): string
    {
        return number_format(max(0, $value), 6, '.', '');
    }

    private function nextPlanNumber(): string
    {
        do {
            $number = 'MRP-' . now()->format('Ymd') . '-'
            . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } while (MaterialRequirementPlan::query()->where('plan_no', $number)->exists());

        return $number;
    }

    /** @return array<int, string> */
    private function detailRelations(): array
    {
        return [
            'demandPlan.branch',
            'demandPlan.period',
            'branch',
            'period',
            'finishedProductLines.finishedProduct.baseUnit',
            'componentLines.rawMaterial.baseUnit',
            'componentLines.part.baseUnit',
            'generator',
            'approver',
        ];
    }
}
