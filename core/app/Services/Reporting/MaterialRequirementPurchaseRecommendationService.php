<?php

namespace App\Services\Reporting;

use App\Models\Reporting\MaterialRequirementPlan;
use App\Models\Reporting\MrpComponentLine;
use Illuminate\Support\Facades\DB;

final class MaterialRequirementPurchaseRecommendationService
{
    /**
     * Build purchase-ready shortage groups from the quantities frozen in an MRP.
     *
     * @return array{
     *     total_items: int,
     *     total_estimated_cost: string,
     *     total_suppliers: int,
     *     suppliers: array<int, array<string, mixed>>,
     *     unmapped_items: array<int, array<string, mixed>>
     * }
     */
    public function forPlan(MaterialRequirementPlan $plan): array
    {
        $plan->loadMissing([
            'componentLines.rawMaterial.baseUnit',
            'componentLines.part.baseUnit',
        ]);

        $supplierGroups = [];
        $unmappedItems = [];
        $estimatedTotal = '0.00';
        $itemCount = 0;

        foreach ($plan->componentLines as $line) {
            $purchaseQuantity = (float) $line->buy_quantity;
            if ($purchaseQuantity <= 0) {
                continue;
            }

            $itemCount++;
            $context = $this->lineContext($line);
            $supplier = $this->preferredSupplier(
                $context['domain'],
                $context['item_id'],
            );
            $unitRate = $this->estimatedBaseUnitRate(
                $plan,
                $context['domain'],
                $context['item_id'],
            );
            $lineTotal = bcmul((string) $purchaseQuantity, $unitRate, 2);
            $estimatedTotal = bcadd($estimatedTotal, $lineTotal, 2);

            $recommendation = $this->recommendationLine(
                $line,
                $context,
                $purchaseQuantity,
                $unitRate,
                $lineTotal,
            );

            if ($supplier === null) {
                $unmappedItems[] = $recommendation;

                continue;
            }

            $supplierId = (int) $supplier->id;
            if (! isset($supplierGroups[$supplierId])) {
                $supplierGroups[$supplierId] = [
                    'supplier_id' => $supplierId,
                    'supplier_name' => $supplier->name,
                    'supplier_code' => $supplier->code,
                    'lead_days' => (int) $supplier->lead_days,
                    'is_preferred' => (bool) $supplier->is_preferred,
                    'items' => [],
                    'order_lines' => [],
                    'supplier_estimated_cost' => '0.00',
                ];
            }

            $supplierGroups[$supplierId]['items'][] = $recommendation;
            $supplierGroups[$supplierId]['order_lines'][] = $recommendation['order_line'];
            $supplierGroups[$supplierId]['supplier_estimated_cost'] = bcadd(
                $supplierGroups[$supplierId]['supplier_estimated_cost'],
                $lineTotal,
                2,
            );
        }

        return [
            'total_items' => $itemCount,
            'total_estimated_cost' => $estimatedTotal,
            'total_suppliers' => count($supplierGroups),
            'suppliers' => array_values($supplierGroups),
            'unmapped_items' => $unmappedItems,
        ];
    }

    /** @return array<string, mixed> */
    private function lineContext(MrpComponentLine $line): array
    {
        $isRawMaterial = $line->component_type === 'RAW_MATERIAL';
        $item = $isRawMaterial ? $line->rawMaterial : $line->part;
        $unit = $item?->baseUnit;

        return [
            'domain' => $isRawMaterial ? 'raw_material' : 'part',
            'domain_label' => $isRawMaterial ? 'Raw Material' : 'Part',
            'item_id' => $isRawMaterial
                ? (int) $line->raw_material_id
                : (int) $line->part_id,
            'item_name' => $item?->name ?? '—',
            'item_code' => $line->componentCode(),
            'unit_id' => $unit?->id,
            'unit_name' => $unit?->name ?? 'Unit',
            'unit_code' => $unit?->code ?? '',
            'unit_precision' => (int) ($unit?->precision ?? 2),
        ];
    }

    private function preferredSupplier(string $domain, int $itemId): ?object
    {
        $isRawMaterial = $domain === 'raw_material';
        $mappingTable = $isRawMaterial ? 'raw_material_suppliers' : 'part_suppliers';
        $itemColumn = $isRawMaterial ? 'raw_material_id' : 'part_id';

        return DB::table($mappingTable.' as supplier_map')
            ->join('suppliers as supplier', 'supplier.id', '=', 'supplier_map.supplier_id')
            ->where('supplier_map.'.$itemColumn, $itemId)
            ->whereNull('supplier.deleted_at')
            ->where('supplier.is_active', true)
            ->orderByDesc('supplier_map.is_preferred')
            ->orderBy('supplier_map.id')
            ->select([
                'supplier.id',
                'supplier.name',
                'supplier.code',
                'supplier_map.lead_days',
                'supplier_map.supplier_code',
                'supplier_map.is_preferred',
            ])
            ->first();
    }

    private function estimatedBaseUnitRate(
        MaterialRequirementPlan $plan,
        string $domain,
        int $itemId,
    ): string {
        $itemColumn = $domain === 'raw_material'
            ? 'raw_material_id'
            : 'part_id';

        $receiptRate = DB::table('purchase_receipt_item as item')
            ->join('purchase_receipt as receipt', 'receipt.id', '=', 'item.purchase_receipt_id')
            ->where('receipt.status', 'POSTED')
            ->where('item.'.$itemColumn, $itemId)
            ->orderByDesc('receipt.receipt_date')
            ->orderByDesc('item.id')
            ->selectRaw('item.unit_rate / NULLIF(item.conversion_to_base, 0) as base_unit_rate')
            ->value('base_unit_rate');

        if ((float) $receiptRate > 0) {
            return number_format((float) $receiptRate, 4, '.', '');
        }

        $orderRate = DB::table('purchase_order_item as item')
            ->join('purchase_order as purchase_order', 'purchase_order.id', '=', 'item.purchase_order_id')
            ->whereIn('purchase_order.status', ['APPROVED', 'PARTIAL', 'RECEIVED'])
            ->where('item.'.$itemColumn, $itemId)
            ->orderByDesc('purchase_order.order_date')
            ->orderByDesc('item.id')
            ->selectRaw('item.unit_rate / NULLIF(item.conversion_to_base, 0) as base_unit_rate')
            ->value('base_unit_rate');

        if ((float) $orderRate > 0) {
            return number_format((float) $orderRate, 4, '.', '');
        }

        $rateTable = $domain === 'raw_material'
            ? 'monthly_raw_material_rates'
            : 'monthly_part_rates';

        $monthlyRate = DB::table($rateTable.' as rate')
            ->join('monthly_rate_sheets as sheet', 'sheet.id', '=', 'rate.monthly_rate_sheet_id')
            ->where('sheet.reporting_period_id', $plan->reporting_period_id)
            ->whereIn('sheet.status', ['APPROVED', 'LOCKED'])
            ->where('rate.'.$itemColumn, $itemId)
            ->value('rate.rate');

        return number_format(max(0, (float) $monthlyRate), 4, '.', '');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function recommendationLine(
        MrpComponentLine $line,
        array $context,
        float $purchaseQuantity,
        string $unitRate,
        string $lineTotal,
    ): array {
        $quantity = $this->formatQuantity(
            $purchaseQuantity,
            $context['unit_precision'],
        );
        $unitRateInput = (float) $unitRate > 0
            ? number_format((float) $unitRate, 2, '.', '')
            : '';

        return [
            'domain' => $context['domain'],
            'domain_label' => $context['domain_label'],
            'item_id' => $context['item_id'],
            'item_name' => $context['item_name'],
            'item_code' => $context['item_code'],
            'unit_id' => $context['unit_id'],
            'unit_code' => $context['unit_code'],
            'unit_precision' => $context['unit_precision'],
            'net_requirement' => (string) $line->net_requirement,
            'purchase_quantity' => (string) $line->buy_quantity,
            'quantity_formatted' => $quantity,
            'estimated_unit_rate' => number_format((float) $unitRate, 2, '.', ''),
            'estimated_total' => $lineTotal,
            'order_line' => [
                'domain' => $context['domain'],
                'domain_label' => $context['domain_label'],
                'item_id' => $context['item_id'],
                'item_text' => $context['item_name'].' ('.$context['item_code'].')',
                'unit_id' => $context['unit_id'],
                'unit_text' => $context['unit_name'].' ('.$context['unit_code'].')',
                'unit_precision' => $context['unit_precision'],
                'quantity' => (string) $line->buy_quantity,
                'quantity_input' => $quantity,
                'unit_rate' => $unitRateInput,
                'line_total' => $lineTotal,
            ],
        ];
    }

    private function formatQuantity(float $quantity, int $precision): string
    {
        $precision = max(0, min(3, $precision));

        // A legacy unit may be configured with zero decimal precision even
        // though an actual BOM requirement is fractional (for example 0.4
        // KG). Never round a meaningful procurement requirement down to zero.
        $hasFraction = abs($quantity - round($quantity)) > 0.0000005;
        if ($hasFraction && $precision === 0) {
            $precision = 3;
        }

        if ($precision === 0) {
            return number_format($quantity, 0, '.', '');
        }

        return rtrim(
            rtrim(number_format($quantity, $precision, '.', ''), '0'),
            '.',
        );
    }
}
