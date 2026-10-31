<?php

namespace App\Http\Controllers\backend;

use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StockLedgerController extends Controller
{
    private const DOMAINS = [
        'raw_material' => [
            'label' => 'Raw Material',
            'movement_table' => 'raw_material_stock_movements',
            'item_table' => 'raw_materials',
            'item_column' => 'raw_material_id',
            'code_column' => 'material_code',
        ],
        'part' => [
            'label' => 'Part',
            'movement_table' => 'part_stock_movements',
            'item_table' => 'parts',
            'item_column' => 'part_id',
            'code_column' => 'part_code',
        ],
        'finished_product' => [
            'label' => 'Finished Product',
            'movement_table' => 'finished_product_stock_movements',
            'item_table' => 'finished_products',
            'item_column' => 'finished_product_id',
            'code_column' => 'product_code',
        ],
    ];

    public function index(): View
    {
        $branches = DB::table('branches')->whereNull('deleted_at')->where('is_active', true)
            ->when(current_branch_id(), fn (Builder $query, int $branchId) => $query->where('id', $branchId))
            ->orderBy('name')->get(['id', 'name', 'code']);

        $warehouses = DB::table('warehouses as w')->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->whereNull('w.deleted_at')->whereNull('b.deleted_at')->where('w.is_active', true)->where('b.is_active', true)
            ->when(current_branch_id(), fn (Builder $query, int $branchId) => $query->where('w.branch_id', $branchId))
            ->orderBy('b.name')->orderBy('w.name')
            ->get(['w.id', 'w.branch_id', 'w.name', 'w.code', 'b.name as branch_name']);

        return view('backend.modules.stock_ledger.index', [
            'domains' => self::DOMAINS,
            'branches' => $branches,
            'warehouses' => $warehouses,
            'movementTypes' => StockMovementType::cases(),
        ]);
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = [
            'movement_at', 'movement_at', 'domain', 'item_name', 'warehouse_name', 'movement_type',
            'source_reference', 'signed_quantity', 'signed_quantity', 'unit_cost', 'running_balance',
            'posted_by_name', 'movement_id',
        ];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 1);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));

        $query = $this->ledgerQuery()
            ->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('branch_id', $branchId));

        $total = (clone $query)->count();
        $this->applyFilters($query, $request);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('item_name', 'like', "%{$search}%")
                    ->orWhere('item_code', 'like', "%{$search}%")
                    ->orWhere('warehouse_name', 'like', "%{$search}%")
                    ->orWhere('warehouse_code', 'like', "%{$search}%")
                    ->orWhere('branch_name', 'like', "%{$search}%")
                    ->orWhere('movement_type', 'like', "%{$search}%")
                    ->orWhere('source_reference', 'like', "%{$search}%")
                    ->orWhere('note', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $rows = $query->orderBy($columns[$orderIndex] ?? 'movement_at', $orderDirection)
            ->orderBy('movement_id', $orderDirection)->skip($start)->take($length)->get();
        $user = $request->user();

        $data = $rows->map(function (object $row) use ($user): array {
            $quantity = $this->formatQuantity($row->quantity, (int) $row->unit_precision);
            $balance = $this->formatQuantity($row->running_balance, (int) $row->unit_precision);
            $isIn = $row->direction === StockDirection::In->value;
            $movementClass = $isIn ? 'bg-success-focus text-success-main' : 'bg-danger-focus text-danger-main';
            $referenceUrl = $this->sourceUrl($row, $user);
            $action = $referenceUrl
                ? '<a href="'.e($referenceUrl).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main" title="View source document"><iconify-icon icon="mdi:file-eye-outline"></iconify-icon></a>'
                : '<span class="text-muted">—</span>';

            return [
                e($row->domain.'-'.$row->movement_id),
                e(date('d M Y, h:i A', strtotime($row->movement_at))),
                '<span class="badge bg-light text-dark">'.e(self::DOMAINS[$row->domain]['label']).'</span>',
                '<strong>'.e($row->item_name).'</strong><br><code>'.e($row->item_code).'</code>',
                '<strong>'.e($row->warehouse_name).'</strong> <code>'.e($row->warehouse_code).'</code><br><small class="text-muted">'.e($row->branch_name).'</small>',
                '<span class="badge '.$movementClass.'">'.e($this->movementLabel($row->movement_type)).'</span>',
                '<span class="fw-semibold">'.e($this->sourceReference($row)).'</span>',
                $isIn ? '<span class="text-success-main fw-semibold">'.e($quantity).' '.e($row->unit_code).'</span>' : '—',
                ! $isIn ? '<span class="text-danger-main fw-semibold">'.e($quantity).' '.e($row->unit_code).'</span>' : '—',
                $row->unit_cost !== null ? e(number_format((float) $row->unit_cost, 4)) : '—',
                '<span class="fw-semibold">'.e($balance).' '.e($row->unit_code).'</span>',
                e($row->posted_by_name ?: 'System'),
                '<div class="d-inline-flex justify-content-end w-100">'.$action.'</div>',
            ];
        })->all();

        return response()->json([
            'draw' => $draw,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $data,
        ]);
    }

    public function itemSelect2(Request $request): JsonResponse
    {
        $domain = (string) $request->input('domain');
        abort_unless(isset(self::DOMAINS[$domain]), 404);
        $config = self::DOMAINS[$domain];
        $search = trim((string) $request->input('q', ''));

        $query = DB::table($config['item_table'].' as i')->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->whereNull('i.deleted_at')->where('i.is_active', true);
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $config): void {
                $builder->where('i.name', 'like', "%{$search}%")
                    ->orWhere('i.'.$config['code_column'], 'like', "%{$search}%")
                    ->orWhere('i.barcode', 'like', "%{$search}%");
            });
        }

        $results = $query->orderBy('i.name')->limit(20)
            ->get(['i.id', 'i.name', 'i.'.$config['code_column'].' as code', 'u.code as unit_code'])
            ->map(fn (object $item): array => [
                'id' => $item->id,
                'text' => $item->name.' ('.$item->code.') — '.$item->unit_code,
            ]);

        return response()->json(['results' => $results]);
    }

    private function ledgerQuery(): Builder
    {
        $union = null;
        foreach (self::DOMAINS as $domain => $config) {
            $domainQuery = $this->domainQuery($domain, $config);
            $union = $union ? $union->unionAll($domainQuery) : $domainQuery;
        }

        $withBalance = DB::query()->fromSub($union, 'movement_union')
            ->select('movement_union.*')
            ->selectRaw(
                'SUM(signed_quantity) OVER (PARTITION BY domain, item_id, warehouse_id ORDER BY movement_at ASC, movement_id ASC ROWS UNBOUNDED PRECEDING) as running_balance'
            );

        return DB::query()->fromSub($withBalance, 'ledger_rows');
    }

    /** @param array<string, string> $config */
    private function domainQuery(string $domain, array $config): Builder
    {
        $movementAlias = 'm';

        return DB::table($config['movement_table'].' as '.$movementAlias)
            ->join($config['item_table'].' as i', 'i.id', '=', $movementAlias.'.'.$config['item_column'])
            ->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->join('warehouses as w', 'w.id', '=', $movementAlias.'.warehouse_id')
            ->join('branches as b', 'b.id', '=', $movementAlias.'.branch_id')
            ->leftJoin('users as poster', 'poster.id', '=', $movementAlias.'.posted_by')
            ->leftJoin('stock_adjustments as sa', function (JoinClause $join) use ($movementAlias): void {
                $join->on('sa.id', '=', $movementAlias.'.source_id')
                    ->where($movementAlias.'.source_type', '=', 'stock_adjustment');
            })
            ->leftJoin('stock_transfers as st', function (JoinClause $join) use ($movementAlias): void {
                $join->on('st.id', '=', $movementAlias.'.source_id')
                    ->where($movementAlias.'.source_type', '=', 'stock_transfer');
            })
            ->selectRaw('? as domain', [$domain])
            ->addSelect([
                $movementAlias.'.id as movement_id',
                $movementAlias.'.'.$config['item_column'].' as item_id',
                'i.name as item_name',
                'i.'.$config['code_column'].' as item_code',
                'u.code as unit_code',
                'u.precision as unit_precision',
                $movementAlias.'.branch_id',
                'b.name as branch_name',
                $movementAlias.'.warehouse_id',
                'w.name as warehouse_name',
                'w.code as warehouse_code',
                $movementAlias.'.movement_at',
                $movementAlias.'.movement_type',
                $movementAlias.'.direction',
                $movementAlias.'.quantity',
                $movementAlias.'.unit_cost',
                $movementAlias.'.source_type',
                $movementAlias.'.source_id',
                $movementAlias.'.source_item_id',
                $movementAlias.'.note',
                $movementAlias.'.posted_by',
                'poster.name as posted_by_name',
            ])
            ->selectRaw("CASE WHEN {$movementAlias}.direction = 'IN' THEN {$movementAlias}.quantity ELSE -{$movementAlias}.quantity END as signed_quantity")
            ->selectRaw("COALESCE(sa.adjustment_no, st.transfer_no, {$movementAlias}.source_type) as source_reference");
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $domain = (string) $request->input('domain');
        if (isset(self::DOMAINS[$domain])) {
            $query->where('domain', $domain);
            if ((int) $request->input('item_id') > 0) {
                $query->where('item_id', (int) $request->input('item_id'));
            }
        }

        if ((int) $request->input('branch_id') > 0) {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }
        if ((int) $request->input('warehouse_id') > 0) {
            $query->where('warehouse_id', (int) $request->input('warehouse_id'));
        }

        $movementType = (string) $request->input('movement_type');
        if (StockMovementType::tryFrom($movementType)) {
            $query->where('movement_type', $movementType);
        }
        $direction = (string) $request->input('direction');
        if (StockDirection::tryFrom($direction)) {
            $query->where('direction', $direction);
        }

        $dateFrom = (string) $request->input('date_from');
        if ($this->validDate($dateFrom)) {
            $query->where('movement_at', '>=', $dateFrom.' 00:00:00');
        }
        $dateTo = (string) $request->input('date_to');
        if ($this->validDate($dateTo)) {
            $query->where('movement_at', '<=', $dateTo.' 23:59:59');
        }
    }

    private function sourceUrl(object $row, mixed $user): ?string
    {
        if (! $user) {
            return null;
        }

        if ($row->source_type === 'stock_transfer' && $user->canDo('inventory.stock-transfers.show')) {
            return route('inventory.stock-transfers.show', $row->source_id);
        }
        if ($row->source_type === 'stock_adjustment') {
            if ($row->movement_type === StockMovementType::Opening->value && $user->canDo('inventory.opening-stocks.show')) {
                return route('inventory.opening-stocks.show', $row->source_id);
            }
            if ($row->movement_type !== StockMovementType::Opening->value && $user->canDo('inventory.stock-adjustments.show')) {
                return route('inventory.stock-adjustments.show', $row->source_id);
            }
        }

        return null;
    }

    private function movementLabel(string $movementType): string
    {
        return str($movementType)->replace('_', ' ')->title()->toString();
    }

    private function sourceReference(object $row): string
    {
        if (in_array($row->source_type, ['stock_adjustment', 'stock_transfer'], true)) {
            return (string) $row->source_reference;
        }

        return str((string) $row->source_reference)->replace('_', ' ')->title().' #'.$row->source_id;
    }

    private function formatQuantity(string|float|int $quantity, int $precision): string
    {
        return number_format((float) $quantity, max(0, min(6, $precision)), '.', ',');
    }

    private function validDate(string $date): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && date('Y-m-d', strtotime($date)) === $date;
    }
}
