<?php

namespace App\Services\Reporting;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MonthlyReportSnapshotDataTableService
{
    /** @param Collection<int, array<string, mixed>> $registerRows */
    public function registerResponse(Request $request, Collection $registerRows): JsonResponse
    {
        $rows = $registerRows->map(function (array $registerRow): array {
            $period = $registerRow['period'];
            $rateSheet = $registerRow['rate_sheet'];
            $current = $registerRow['current_snapshot'];

            return [
                'row_key' => (int) sprintf('%04d%02d', $period->year, $period->month),
                'period_label' => sprintf('%04d-%02d', $period->year, $period->month),
                'date_range' => $period->starts_on->format('d M').' - '.$period->ends_on->format('d M Y'),
                'period_status' => $period->status->label(),
                'period_status_class' => $period->status->value === 'CLOSED'
                    ? 'bg-success-focus text-success-main'
                    : 'bg-warning-focus text-warning-main',
                'rate_sheet_no' => $rateSheet?->sheet_no,
                'rate_sheet_status' => $rateSheet?->status->label(),
                'snapshot_version' => $current?->version,
                'version_count' => $registerRow['version_count'],
                'generated_at' => $current?->generated_at?->format('d M Y, h:i A'),
                'generated_by' => $current?->generator?->name ?: ($current ? 'System' : null),
                'action_html' => view(
                    'backend.modules.monthly_report_snapshot.partials.register_action',
                    compact('registerRow'),
                )->render(),
            ];
        });

        return $this->response(
            $request,
            $rows,
            [
                'row_key',
                'period_label',
                'period_status',
                'rate_sheet_no',
                'snapshot_version',
                'generated_at',
                null,
            ],
            fn (array $row): array => [
                $row['row_key'],
                '<strong>'.e($row['period_label']).'</strong><small class="d-block text-muted">'.e($row['date_range']).'</small>',
                '<span class="badge '.e($row['period_status_class']).'">'.e($row['period_status']).'</span>',
                $row['rate_sheet_no']
                    ? '<strong>'.e($row['rate_sheet_no']).'</strong><small class="d-block text-muted">'.e($row['rate_sheet_status']).'</small>'
                    : '<span class="text-muted">Not prepared</span>',
                $row['snapshot_version']
                    ? '<span class="badge bg-primary-focus text-primary-main">Version '.e((string) $row['snapshot_version']).'</span><small class="d-block text-muted mt-1">'.e((string) $row['version_count']).' version(s)</small>'
                    : '<span class="text-muted">Not generated</span>',
                $row['generated_at']
                    ? e($row['generated_at']).'<small class="d-block text-muted">'.e($row['generated_by']).'</small>'
                    : '<span class="text-muted">&mdash;</span>',
                $row['action_html'],
            ],
        );
    }

    /** @param array<string, mixed> $report */
    public function reportResponse(Request $request, string $section, array $report): JsonResponse
    {
        // Snapshot presentation contains metadata/dimensions alongside the six reports.
        // Accepting an already-unwrapped report array keeps this service reusable in tests
        // and other read-only report consumers.
        $reports = $report['reports'] ?? $report;

        return match ($section) {
            'valuation' => $this->valuationResponse($request, $reports['stock_valuation']['rows'] ?? []),
            'purchase' => $this->purchaseResponse($request, $reports['purchase']['lines'] ?? []),
            'stock' => $this->stockResponse($request, $reports['stock']['rows'] ?? []),
            'part-production' => $this->productionResponse(
                $request,
                $reports['part_production']['rows'] ?? [],
                'production_date',
                'part_name',
                'part_code',
                'material_cost',
            ),
            'assembly' => $this->productionResponse(
                $request,
                $reports['assembly']['rows'] ?? [],
                'assembly_date',
                'finished_product_name',
                'product_code',
                'component_cost',
            ),
            'sales' => $this->salesResponse($request, $reports['sales']['sales'] ?? []),
            default => response()->json([
                'draw' => $request->integer('draw'),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
            ], 422),
        };
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function valuationResponse(Request $request, array $rows): JsonResponse
    {
        return $this->response(
            $request,
            collect($rows),
            ['item_code', 'domain_label', 'item_name', 'branch_name', 'quantity', 'rate', 'value'],
            fn (array $row): array => [
                $this->rowKey($row, 'item_code'),
                '<span class="badge bg-light text-dark">'.e($row['domain_label']).'</span>',
                $this->itemHtml($row['item_name'], $row['item_code']),
                $this->locationHtml($row),
                $this->quantity($row['quantity']).' '.e($row['unit_code']),
                $this->money($row['rate']),
                '<span class="fw-bold">'.$this->money($row['value']).'</span>',
            ],
        );
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function purchaseResponse(Request $request, array $rows): JsonResponse
    {
        return $this->response(
            $request,
            collect($rows),
            ['receipt_no', 'receipt_no', 'domain', 'item_name', 'base_quantity', 'unit_rate', 'line_total'],
            fn (array $row): array => [
                $this->rowKey($row, 'receipt_no'),
                '<strong>'.e($row['receipt_no']).'</strong><small class="d-block text-muted">'.e($row['receipt_date']).'</small>',
                e(str_replace('_', ' ', $row['domain'])),
                $this->itemHtml($row['item_name'], $row['item_code']),
                $this->quantity($row['base_quantity']).' '.e($row['unit_code']),
                $this->money($row['unit_rate']),
                '<span class="fw-bold">'.$this->money($row['line_total']).'</span>',
            ],
        );
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function stockResponse(Request $request, array $rows): JsonResponse
    {
        return $this->response(
            $request,
            collect($rows),
            ['item_code', 'domain_label', 'item_name', 'branch_name', 'opening', 'in', 'out', 'closing'],
            fn (array $row): array => [
                $this->rowKey($row, 'item_code'),
                '<span class="badge bg-light text-dark">'.e($row['domain_label']).'</span>',
                $this->itemHtml($row['item_name'], $row['item_code']),
                $this->locationHtml($row),
                $this->quantity($row['opening']),
                '<span class="text-success-main fw-semibold">'.$this->quantity($row['in']).'</span>',
                '<span class="text-danger-main fw-semibold">'.$this->quantity($row['out']).'</span>',
                '<span class="fw-bold">'.$this->quantity($row['closing']).' '.e($row['unit_code']).'</span>',
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function productionResponse(
        Request $request,
        array $rows,
        string $dateField,
        string $nameField,
        string $codeField,
        string $componentField,
    ): JsonResponse {
        return $this->response(
            $request,
            collect($rows),
            ['batch_no', 'batch_no', $nameField, 'input_warehouse_name', 'planned_quantity', 'good_quantity', 'reject_quantity', $componentField, 'direct_cost'],
            fn (array $row): array => [
                $this->rowKey($row, 'batch_no'),
                '<strong>'.e($row['batch_no']).'</strong><small class="d-block text-muted">'.e($row[$dateField]).'</small>',
                $this->itemHtml($row[$nameField], $row[$codeField]),
                e($row['input_warehouse_name']).'<small class="d-block text-muted">To: '.e($row['output_warehouse_name']).'</small>',
                $this->quantity($row['planned_quantity']),
                '<span class="text-success-main fw-semibold">'.$this->quantity($row['good_quantity']).'</span>',
                '<span class="text-danger-main fw-semibold">'.$this->quantity($row['reject_quantity']).'</span>',
                $this->money($row[$componentField] ?? 0),
                $this->money($row['direct_cost'] ?? 0),
            ],
        );
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function salesResponse(Request $request, array $rows): JsonResponse
    {
        return $this->response(
            $request,
            collect($rows),
            ['sale_no', 'sale_no', 'channel', 'customer_name', 'branch_name', 'total', 'paid_amount', 'due_amount'],
            fn (array $row): array => [
                $this->rowKey($row, 'sale_no'),
                '<strong>'.e($row['sale_no']).'</strong><small class="d-block text-muted">'.e($row['sale_at']).'</small>',
                e($row['channel']),
                e($row['customer_name'] ?: 'Walk-in'),
                $this->locationHtml($row),
                '<span class="fw-bold">'.$this->money($row['total']).'</span>',
                '<span class="text-success-main fw-semibold">'.$this->money($row['paid_amount']).'</span>',
                '<span class="text-danger-main fw-semibold">'.$this->money($row['due_amount']).'</span>',
            ],
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, string|null>  $sortColumns
     * @param  callable(array<string, mixed>): array<int, mixed>  $formatter
     */
    private function response(
        Request $request,
        Collection $rows,
        array $sortColumns,
        callable $formatter,
    ): JsonResponse {
        $total = $rows->count();
        $search = mb_strtolower(trim((string) $request->input('search.value', '')));

        if ($search !== '') {
            $rows = $rows->filter(
                fn (array $row): bool => str_contains($this->searchableText($row), $search),
            )->values();
        }

        $filtered = $rows->count();
        $orderColumn = (int) $request->input('order.0.column', 0);
        $sortKey = $sortColumns[$orderColumn] ?? null;
        $direction = strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc'
            ? 'desc'
            : 'asc';

        if ($sortKey) {
            $rows = $direction === 'desc'
                ? $rows->sortByDesc($sortKey, SORT_NATURAL | SORT_FLAG_CASE)
                : $rows->sortBy($sortKey, SORT_NATURAL | SORT_FLAG_CASE);
        }

        $start = max($request->integer('start'), 0);
        $length = $request->integer('length', 10);
        if ($length !== -1) {
            $rows = $rows->slice($start, min(max($length, 1), 1000));
        }

        $data = $rows->values()->map($formatter)->all();

        return response()->json([
            'draw' => $request->integer('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $data,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function searchableText(array $row): string
    {
        $values = [];
        array_walk_recursive($row, function (mixed $value) use (&$values): void {
            if (is_scalar($value) || $value === null) {
                $values[] = (string) $value;
            }
        });

        return mb_strtolower(implode(' ', $values));
    }

    /** @param array<string, mixed> $row */
    private function rowKey(array $row, string $fallback): string
    {
        return (string) ($row['id'] ?? $row[$fallback] ?? md5(json_encode($row)));
    }

    private function itemHtml(mixed $name, mixed $code): string
    {
        return '<strong>'.e($name).'</strong><br><code>'.e($code).'</code>';
    }

    /** @param array<string, mixed> $row */
    private function locationHtml(array $row): string
    {
        return e($row['branch_name']).'<small class="d-block text-muted">'.e($row['warehouse_name']).'</small>';
    }

    private function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
