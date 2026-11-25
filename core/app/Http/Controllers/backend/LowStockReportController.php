<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Services\Reporting\LowStockReportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LowStockReportController extends Controller
{
    public function __construct(private readonly LowStockReportService $reportService) {}

    public function index(Request $request): View
    {
        $fixedBranchId = $this->accessibleBranchId($request);
        $branches = DB::table('branches')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->when($fixedBranchId, fn (Builder $query, int $branchId) => $query->where('id', $branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        $warehouses = DB::table('warehouses as warehouse')
            ->join('branches as branch', 'branch.id', '=', 'warehouse.branch_id')
            ->whereNull('warehouse.deleted_at')
            ->whereNull('branch.deleted_at')
            ->where('warehouse.is_active', true)
            ->where('branch.is_active', true)
            ->when($fixedBranchId, fn (Builder $query, int $branchId) => $query->where('warehouse.branch_id', $branchId))
            ->orderBy('branch.name')
            ->orderBy('warehouse.name')
            ->get([
                'warehouse.id',
                'warehouse.branch_id',
                'warehouse.name',
                'warehouse.code',
                'branch.name as branch_name',
            ]);

        $filters = $this->filters($request, $fixedBranchId);
        $summary = $this->reportService->summary(
            $filters['branch_id'],
            $filters['warehouse_id'],
            $filters['domain'],
            $filters['alert_status'],
        );

        return view('backend.modules.low_stock_report.index', [
            'branches' => $branches,
            'warehouses' => $warehouses,
            'fixedBranchId' => $fixedBranchId,
            'filters' => $filters,
            'summary' => $summary,
        ]);
    }

    public function listAjax(Request $request): JsonResponse
    {
        $filters = $this->filters($request, $this->accessibleBranchId($request));
        $draw = $request->integer('draw');
        $start = max($request->integer('start'), 0);
        $requestedLength = $request->integer('length', 10);
        $length = $requestedLength === -1 ? 1000 : min(max($requestedLength, 1), 1000);
        $search = trim((string) $request->input('search.value', ''));
        $orderColumn = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc'
            ? 'desc'
            : 'asc';
        $orderColumns = [
            'item_name',
            'alert_status',
            'domain_label',
            'item_name',
            'warehouse_name',
            'current_quantity',
            'minimum_quantity',
            'shortfall_quantity',
            'reorder_quantity',
            'maximum_quantity',
            'action_type',
        ];

        $query = $this->reportService->query(
            $filters['branch_id'],
            $filters['warehouse_id'],
            $filters['domain'],
            $filters['alert_status'],
        );
        $recordsTotal = (clone $query)->count();

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $like = "%{$search}%";
                $builder
                    ->where('item_name', 'like', $like)
                    ->orWhere('item_code', 'like', $like)
                    ->orWhere('domain_label', 'like', $like)
                    ->orWhere('branch_name', 'like', $like)
                    ->orWhere('branch_code', 'like', $like)
                    ->orWhere('warehouse_name', 'like', $like)
                    ->orWhere('warehouse_code', 'like', $like)
                    ->orWhere('alert_status', 'like', $like)
                    ->orWhere('action_type', 'like', $like);
            });
        }

        $recordsFiltered = (clone $query)->count();
        $rows = $query
            ->orderBy($orderColumns[$orderColumn] ?? 'item_name', $orderDirection)
            ->orderBy('item_name')
            ->skip($start)
            ->take($length)
            ->get();
        $data = $rows->values()->map(
            fn (object $row, int $index): array => $this->dataRow($row, $start + $index + 1),
        )->all();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
            'iTotalRecords' => $recordsTotal,
            'iTotalDisplayRecords' => $recordsFiltered,
            'aaData' => $data,
        ]);
    }

    /** @return array<string, int|string|null> */
    private function filters(Request $request, ?int $fixedBranchId): array
    {
        $domain = strtoupper(trim((string) $request->input('domain', '')));
        $alertStatus = strtoupper(trim((string) $request->input('alert_status', '')));

        return [
            'branch_id' => $fixedBranchId ?: ($request->integer('branch_id') ?: null),
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'domain' => in_array($domain, $this->reportService->domains(), true) ? $domain : '',
            'alert_status' => in_array($alertStatus, $this->reportService->alertStatuses(), true)
                ? $alertStatus
                : '',
        ];
    }

    private function accessibleBranchId(Request $request): ?int
    {
        $branchId = current_branch_id();

        if ($branchId === null && ! $request->user()?->isSuper()) {
            $branchId = (int) $request->user()?->branch_id;
        }

        return $branchId ?: null;
    }

    /** @return array<int, string|int> */
    private function dataRow(object $row, int $serial): array
    {
        $unit = e((string) $row->unit_code);
        $suggestedQuantity = $this->reportService->suggestedQuantity($row);
        $suggested = $suggestedQuantity === null
            ? '<span class="badge bg-info-focus text-info-main px-10 py-6 radius-4">Demand Plan</span>'
            : '<span class="fw-bold text-primary-main">'.$this->formatQuantity($suggestedQuantity).' '.$unit.'</span>';

        return [
            $serial,
            $this->statusBadge((string) $row->alert_status),
            $this->domainBadge((string) $row->domain, (string) $row->domain_label),
            '<strong>'.e((string) $row->item_name).'</strong><br><code>'.e((string) $row->item_code).'</code>',
            '<strong>'.e((string) $row->warehouse_name).'</strong> <code>'.e((string) $row->warehouse_code).'</code>'
                .'<br><small class="text-muted">'.e((string) $row->branch_name).' ('.e((string) $row->branch_code).')</small>',
            '<span class="fw-bold '.((float) $row->current_quantity <= 0 ? 'text-danger-main' : 'text-warning-main').'">'
                .$this->formatQuantity($row->current_quantity).' '.$unit.'</span>',
            '<span class="fw-semibold">'.$this->formatQuantity($row->minimum_quantity).' '.$unit.'</span>',
            '<span class="fw-semibold text-danger-main">'.$this->formatQuantity($row->shortfall_quantity).' '.$unit.'</span>',
            (float) $row->reorder_quantity > 0
                ? $this->formatQuantity($row->reorder_quantity).' '.$unit
                : '<span class="text-muted">Demand-based</span>',
            $row->maximum_quantity !== null
                ? $this->formatQuantity($row->maximum_quantity).' '.$unit
                : '<span class="text-muted">&mdash;</span>',
            $suggested,
            $this->actionBadge((string) $row->action_type),
        ];
    }

    private function statusBadge(string $status): string
    {
        return match ($status) {
            'OUT_OF_STOCK' => '<span class="badge bg-danger-focus text-danger-main px-10 py-6 radius-4">Out of Stock</span>',
            'LOW_STOCK' => '<span class="badge bg-warning-focus text-warning-main px-10 py-6 radius-4">Low Stock</span>',
            default => '<span class="badge bg-info-focus text-info-main px-10 py-6 radius-4">At Reorder Point</span>',
        };
    }

    private function domainBadge(string $domain, string $label): string
    {
        $class = match ($domain) {
            'RAW_MATERIAL' => 'bg-warning-focus text-warning-main',
            'PART' => 'bg-lilac-50 text-lilac-600',
            default => 'bg-success-focus text-success-main',
        };

        return '<span class="badge '.$class.' px-10 py-6 radius-4">'.e($label).'</span>';
    }

    private function actionBadge(string $action): string
    {
        return match ($action) {
            'PURCHASE' => '<span class="badge bg-primary-focus text-primary-main px-10 py-6 radius-4">Purchase</span>',
            'PRODUCTION' => '<span class="badge bg-info-focus text-info-main px-10 py-6 radius-4">Produce Part</span>',
            'ASSEMBLY' => '<span class="badge bg-success-focus text-success-main px-10 py-6 radius-4">Assemble Product</span>',
            default => '<span class="badge bg-lilac-50 text-lilac-600 px-10 py-6 radius-4">Make / Buy Review</span>',
        };
    }

    private function formatQuantity(mixed $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 3, '.', ','), '0'), '.');
    }
}
