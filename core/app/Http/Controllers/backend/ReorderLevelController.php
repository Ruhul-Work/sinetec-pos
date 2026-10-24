<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

abstract class ReorderLevelController extends Controller
{
    /** @return array<string, string> */
    abstract protected function reorderConfig(): array;

    public function index(): View
    {
        return view('backend.modules.reorder_level.index', [
            'config' => $this->reorderConfig(),
        ]);
    }

    public function listAjax(Request $request): JsonResponse
    {
        $config = $this->reorderConfig();
        $columns = ['r.id', 'i.name', 'w.name', 'r.minimum_quantity', 'r.reorder_quantity', 'r.maximum_quantity', 'r.updated_at'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));
        $warehouseId = $request->input('warehouse_id');

        $query = $this->reorderQuery($config);
        $total = (clone $query)->count();

        if (filled($warehouseId)) {
            $query->where('r.warehouse_id', (int) $warehouseId);
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $config): void {
                $builder->where('i.name', 'like', "%{$search}%")
                    ->orWhere('i.'.$config['code_column'], 'like', "%{$search}%")
                    ->orWhere('w.name', 'like', "%{$search}%")
                    ->orWhere('w.code', 'like', "%{$search}%")
                    ->orWhere('b.name', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $rows = $query->orderBy($columns[$orderIndex] ?? 'r.id', $orderDirection)
            ->skip($start)
            ->take($length)
            ->get();

        $routeName = $config['route_name'];
        $user = $request->user();
        $canEdit = $user?->canDo($routeName.'.update', 'edit') ?? false;
        $canDelete = $user?->canDo($routeName.'.destroy', 'delete') ?? false;

        $data = $rows->map(function (object $level) use ($routeName, $canEdit, $canDelete): array {
            $unit = e($level->unit_code);
            $reorder = (float) $level->reorder_quantity > 0
                ? '<span class="fw-medium">'.$this->formatQuantity($level->reorder_quantity).' '.$unit.'</span>'
                : '<span class="badge text-sm fw-semibold bg-dark-info-gradient px-12 py-7 radius-4 text-white">Demand-based</span>';
            $maximum = $level->maximum_quantity !== null
                ? '<span class="fw-medium">'.$this->formatQuantity($level->maximum_quantity).' '.$unit.'</span>'
                : '&mdash;';
            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">';

            if ($canEdit) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main AjaxModal"'
                    .' data-ajax-modal="'.e(route($routeName.'.editModal', $level->id)).'"'
                    .' data-size="md" data-onload="ReorderLevelsIndex.onLoad" data-onsuccess="ReorderLevelsIndex.onSaved" title="Edit">'
                    .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }

            if ($canDelete) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-reorder-level-delete"'
                    .' data-url="'.e(route($routeName.'.destroy', $level->id)).'" title="Delete">'
                    .'<iconify-icon icon="mdi:delete"></iconify-icon></a>';
            }

            $actions .= '</div>';

            return [
                $level->id,
                '<strong>'.e($level->item_name).'</strong><br><code>'.e($level->item_code).'</code>',
                '<strong>'.e($level->warehouse_name).'</strong><br><code>'.e($level->warehouse_code).'</code> &middot; '.e($level->branch_name),
                '<span class="fw-medium">'.$this->formatQuantity($level->minimum_quantity).' '.$unit.'</span>',
                $reorder,
                $maximum,
                e($level->updated_at ? date('d M Y, h:i A', strtotime($level->updated_at)) : '—'),
                $actions,
            ];
        })->all();

        return response()->json([
            'draw' => $draw,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $data,
        ]);
    }

    public function createModal(): View
    {
        $config = $this->reorderConfig();

        return $this->modalView($config, null, route($config['route_name'].'.store'), 'POST');
    }

    public function store(Request $request): JsonResponse
    {
        $config = $this->reorderConfig();
        $data = $this->validated($request, $config);
        $id = $this->persist($config, $data);

        return response()->json([
            'success' => true,
            'msg' => $config['singular'].' reorder level created successfully.',
            'id' => $id,
        ], 201);
    }

    public function editModal(int $level): View
    {
        $config = $this->reorderConfig();
        $record = $this->reorderLevel($config, $level);

        return $this->modalView(
            $config,
            $record,
            route($config['route_name'].'.update', $level),
            'PUT'
        );
    }

    public function update(Request $request, int $level): JsonResponse
    {
        $config = $this->reorderConfig();
        $this->reorderLevel($config, $level);
        $data = $this->validated($request, $config, $level);
        $this->persist($config, $data, $level);

        return response()->json([
            'success' => true,
            'msg' => $config['singular'].' reorder level updated successfully.',
        ]);
    }

    public function destroy(int $level): JsonResponse
    {
        $config = $this->reorderConfig();
        $this->reorderLevel($config, $level);
        DB::table($config['reorder_table'])->where('id', $level)->delete();

        return response()->json([
            'success' => true,
            'msg' => $config['singular'].' reorder level deleted successfully.',
        ]);
    }

    public function itemSelect2(Request $request): JsonResponse
    {
        $config = $this->reorderConfig();
        $search = trim((string) $request->input('q', ''));
        $query = DB::table($config['item_table'].' as i')
            ->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->whereNull('i.deleted_at')
            ->where('i.is_active', true);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $config): void {
                $builder->where('i.name', 'like', "%{$search}%")
                    ->orWhere('i.'.$config['code_column'], 'like', "%{$search}%")
                    ->orWhere('i.barcode', 'like', "%{$search}%");
            });
        }

        $results = $query->orderBy('i.name')
            ->limit(20)
            ->get(['i.id', 'i.name', 'i.'.$config['code_column'].' as code', 'u.code as unit_code'])
            ->map(fn (object $item): array => [
                'id' => $item->id,
                'text' => $item->name.' ('.$item->code.') — '.$item->unit_code,
            ]);

        return response()->json(['results' => $results]);
    }

    public function warehouseSelect2(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $query = DB::table('warehouses as w')
            ->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->whereNull('w.deleted_at')
            ->whereNull('b.deleted_at')
            ->where('w.is_active', true)
            ->where('b.is_active', true)
            ->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('w.branch_id', $branchId));

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('w.name', 'like', "%{$search}%")
                    ->orWhere('w.code', 'like', "%{$search}%")
                    ->orWhere('b.name', 'like', "%{$search}%");
            });
        }

        $results = $query->orderByDesc('w.is_default')
            ->orderBy('w.name')
            ->limit(20)
            ->get(['w.id', 'w.name', 'w.code', 'b.name as branch_name'])
            ->map(fn (object $warehouse): array => [
                'id' => $warehouse->id,
                'text' => $warehouse->name.' ('.$warehouse->code.') — '.$warehouse->branch_name,
            ]);

        return response()->json(['results' => $results]);
    }

    /** @param array<string, string> $config */
    private function reorderQuery(array $config): Builder
    {
        return DB::table($config['reorder_table'].' as r')
            ->join($config['item_table'].' as i', 'i.id', '=', 'r.'.$config['item_column'])
            ->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->join('warehouses as w', 'w.id', '=', 'r.warehouse_id')
            ->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->whereNull('i.deleted_at')
            ->whereNull('w.deleted_at')
            ->whereNull('b.deleted_at')
            ->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('w.branch_id', $branchId))
            ->select([
                'r.id', 'r.minimum_quantity', 'r.reorder_quantity', 'r.maximum_quantity', 'r.updated_at',
                'i.id as item_id', 'i.name as item_name', 'i.'.$config['code_column'].' as item_code',
                'u.code as unit_code', 'w.id as warehouse_id', 'w.name as warehouse_name',
                'w.code as warehouse_code', 'b.name as branch_name',
            ]);
    }

    /** @param array<string, string> $config */
    private function modalView(array $config, ?object $level, string $action, string $method): View
    {
        if ($level) {
            $level->item_text = $level->item_name.' ('.$level->item_code.') — '.$level->unit_code;
            $level->warehouse_text = $level->warehouse_name.' ('.$level->warehouse_code.') — '.$level->branch_name;
        }

        return view('backend.modules.reorder_level.modal', [
            'config' => $config,
            'level' => $level,
            'action' => $action,
            'method' => $method,
            'title' => ($level ? 'Edit ' : 'Add ').$config['singular'].' Reorder Level',
        ]);
    }

    /**
     * @param array<string, string> $config
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $config, ?int $levelId = null): array
    {
        $request->merge([
            'reorder_quantity' => filled($request->input('reorder_quantity'))
                ? $request->input('reorder_quantity')
                : 0,
            'maximum_quantity' => filled($request->input('maximum_quantity'))
                ? $request->input('maximum_quantity')
                : null,
        ]);

        $branchId = current_branch_id();
        $data = $request->validate([
            'item_id' => [
                'required', 'integer',
                Rule::exists($config['item_table'], 'id')
                    ->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where(function ($query) use ($branchId): void {
                    $query->whereNull('deleted_at')->where('is_active', true);
                    if ($branchId !== null) {
                        $query->where('branch_id', $branchId);
                    }
                }),
            ],
            'minimum_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.999999'],
            'reorder_quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999.999999'],
            'maximum_quantity' => ['nullable', 'numeric', 'gte:minimum_quantity', 'max:99999999999999.999999'],
        ], [
            'minimum_quantity.gt' => 'The minimum quantity must be greater than zero.',
            'maximum_quantity.gte' => 'The maximum quantity must be greater than or equal to the minimum quantity.',
        ]);

        $duplicate = DB::table($config['reorder_table'])
            ->when($levelId, fn ($query) => $query->where('id', '!=', $levelId))
            ->where($config['item_column'], $data['item_id'])
            ->where('warehouse_id', $data['warehouse_id'])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'A reorder level already exists for this item and warehouse.',
            ]);
        }

        return $data;
    }

    /**
     * @param array<string, string> $config
     * @param array<string, mixed> $data
     */
    private function persist(array $config, array $data, ?int $levelId = null): int
    {
        $values = [
            $config['item_column'] => $data['item_id'],
            'warehouse_id' => $data['warehouse_id'],
            'minimum_quantity' => $data['minimum_quantity'],
            'reorder_quantity' => $data['reorder_quantity'],
            'maximum_quantity' => $data['maximum_quantity'],
            'updated_at' => now(),
        ];

        if ($levelId) {
            DB::table($config['reorder_table'])->where('id', $levelId)->update($values);

            return $levelId;
        }

        $values['created_at'] = now();

        return DB::table($config['reorder_table'])->insertGetId($values);
    }

    /** @param array<string, string> $config */
    private function reorderLevel(array $config, int $level): object
    {
        $record = $this->reorderQuery($config)->where('r.id', $level)->first();
        abort_unless($record, 404);

        return $record;
    }

    private function formatQuantity(string|int|float $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 6, '.', ','), '0'), '.');
    }
}
