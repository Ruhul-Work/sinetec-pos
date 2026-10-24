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

abstract class SupplierMappingController extends Controller
{
    /** @return array<string, string> */
    abstract protected function mappingConfig(): array;

    public function index(): View
    {
        return view('backend.modules.supplier_mapping.index', [
            'config' => $this->mappingConfig(),
        ]);
    }

    public function listAjax(Request $request): JsonResponse
    {
        $config = $this->mappingConfig();
        $columns = ['m.id', 'i.name', 's.name', 'm.supplier_code', 'm.lead_days', 'm.is_preferred', 'm.updated_at'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));
        $preferred = $request->input('preferred');

        $query = $this->mappingQuery($config);
        $total = (clone $query)->count();

        if ($preferred === '1' || $preferred === '0') {
            $query->where('m.is_preferred', $preferred === '1');
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $config): void {
                $builder->where('i.name', 'like', "%{$search}%")
                    ->orWhere('i.'.$config['code_column'], 'like', "%{$search}%")
                    ->orWhere('s.name', 'like', "%{$search}%")
                    ->orWhere('s.code', 'like', "%{$search}%")
                    ->orWhere('m.supplier_code', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $rows = $query->orderBy($columns[$orderIndex] ?? 'm.id', $orderDirection)
            ->skip($start)
            ->take($length)
            ->get();

        $routeName = $config['route_name'];
        $user = $request->user();
        $canEdit = $user?->canDo($routeName.'.update', 'edit') ?? false;
        $canDelete = $user?->canDo($routeName.'.destroy', 'delete') ?? false;

        $data = $rows->map(function (object $mapping) use ($routeName, $canEdit, $canDelete): array {
            $supplierIdentity = $mapping->supplier_master_code
                ? '<br><code>'.e($mapping->supplier_master_code).'</code>'
                : '';
            $preferredBadge = $mapping->is_preferred
                ? '<span class="badge text-sm fw-semibold bg-dark-success-gradient px-12 py-7 radius-4 text-white">Preferred</span>'
                : '<span class="badge text-sm fw-semibold bg-dark-info-gradient px-12 py-7 radius-4 text-white">Standard</span>';
            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">';

            if ($canEdit) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main AjaxModal"'
                    .' data-ajax-modal="'.e(route($routeName.'.editModal', $mapping->id)).'"'
                    .' data-size="md" data-onload="SupplierMappingsIndex.onLoad" data-onsuccess="SupplierMappingsIndex.onSaved" title="Edit">'
                    .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }

            if ($canDelete) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-supplier-mapping-delete"'
                    .' data-url="'.e(route($routeName.'.destroy', $mapping->id)).'" title="Delete">'
                    .'<iconify-icon icon="mdi:delete"></iconify-icon></a>';
            }

            $actions .= '</div>';

            return [
                $mapping->id,
                '<strong>'.e($mapping->item_name).'</strong><br><code>'.e($mapping->item_code).'</code>',
                '<strong>'.e($mapping->supplier_name).'</strong>'.$supplierIdentity,
                $mapping->supplier_code ? '<code>'.e($mapping->supplier_code).'</code>' : '&mdash;',
                '<span class="fw-medium">'.number_format((int) $mapping->lead_days).' day'.((int) $mapping->lead_days === 1 ? '' : 's').'</span>',
                $preferredBadge,
                e($mapping->updated_at ? date('d M Y, h:i A', strtotime($mapping->updated_at)) : '—'),
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
        $config = $this->mappingConfig();

        return $this->modalView(
            $config,
            null,
            route($config['route_name'].'.store'),
            'POST'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $config = $this->mappingConfig();
        $data = $this->validated($request, $config);
        $id = $this->persist($config, $data);

        return response()->json([
            'success' => true,
            'msg' => $config['singular'].' supplier mapping created successfully.',
            'id' => $id,
        ], 201);
    }

    public function editModal(int $mapping): View
    {
        $config = $this->mappingConfig();
        $record = $this->mapping($config, $mapping);

        return $this->modalView(
            $config,
            $record,
            route($config['route_name'].'.update', $mapping),
            'PUT'
        );
    }

    public function update(Request $request, int $mapping): JsonResponse
    {
        $config = $this->mappingConfig();
        $this->mapping($config, $mapping);
        $data = $this->validated($request, $config, $mapping);
        $this->persist($config, $data, $mapping);

        return response()->json([
            'success' => true,
            'msg' => $config['singular'].' supplier mapping updated successfully.',
        ]);
    }

    public function destroy(int $mapping): JsonResponse
    {
        $config = $this->mappingConfig();
        $this->mapping($config, $mapping);
        DB::table($config['mapping_table'])->where('id', $mapping)->delete();

        return response()->json([
            'success' => true,
            'msg' => $config['singular'].' supplier mapping deleted successfully.',
        ]);
    }

    public function itemSelect2(Request $request): JsonResponse
    {
        $config = $this->mappingConfig();
        $search = trim((string) $request->input('q', ''));

        $query = DB::table($config['item_table'])
            ->whereNull('deleted_at')
            ->where('is_active', true);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $config): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere($config['code_column'], 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $results = $query->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', $config['code_column'].' as code'])
            ->map(fn (object $item): array => [
                'id' => $item->id,
                'text' => $item->name.' ('.$item->code.')',
            ]);

        return response()->json(['results' => $results]);
    }

    public function supplierSelect2(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $query = DB::table('suppliers')
            ->whereNull('deleted_at')
            ->where('is_active', true);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $results = $query->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'code'])
            ->map(fn (object $supplier): array => [
                'id' => $supplier->id,
                'text' => $supplier->code ? $supplier->name.' ('.$supplier->code.')' : $supplier->name,
            ]);

        return response()->json(['results' => $results]);
    }

    /** @param array<string, string> $config */
    private function mappingQuery(array $config): Builder
    {
        return DB::table($config['mapping_table'].' as m')
            ->join($config['item_table'].' as i', 'i.id', '=', 'm.'.$config['item_column'])
            ->join('suppliers as s', 's.id', '=', 'm.supplier_id')
            ->whereNull('i.deleted_at')
            ->whereNull('s.deleted_at')
            ->select([
                'm.id', 'm.supplier_code', 'm.lead_days', 'm.is_preferred', 'm.updated_at',
                'i.id as item_id', 'i.name as item_name', 'i.'.$config['code_column'].' as item_code',
                's.id as supplier_id', 's.name as supplier_name', 's.code as supplier_master_code',
            ]);
    }

    /** @param array<string, string> $config */
    private function modalView(array $config, ?object $mapping, string $action, string $method): View
    {
        if ($mapping) {
            $mapping->item_text = $mapping->item_name.' ('.$mapping->item_code.')';
            $mapping->supplier_text = $mapping->supplier_master_code
                ? $mapping->supplier_name.' ('.$mapping->supplier_master_code.')'
                : $mapping->supplier_name;
        }

        return view('backend.modules.supplier_mapping.modal', [
            'config' => $config,
            'mapping' => $mapping,
            'action' => $action,
            'method' => $method,
            'title' => ($mapping ? 'Edit ' : 'Add ').$config['singular'].' Supplier',
        ]);
    }

    /**
     * @param array<string, string> $config
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $config, ?int $mappingId = null): array
    {
        $request->merge([
            'supplier_code' => filled($request->input('supplier_code'))
                ? trim((string) $request->input('supplier_code'))
                : null,
            'is_preferred' => $request->boolean('is_preferred'),
        ]);

        $data = $request->validate([
            'item_id' => [
                'required', 'integer',
                Rule::exists($config['item_table'], 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'supplier_id' => [
                'required', 'integer',
                Rule::exists('suppliers', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'supplier_code' => ['nullable', 'string', 'max:80'],
            'lead_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'is_preferred' => ['required', 'boolean'],
        ]);

        $duplicate = DB::table($config['mapping_table'])
            ->when($mappingId, fn ($query) => $query->where('id', '!=', $mappingId))
            ->where($config['item_column'], $data['item_id'])
            ->where('supplier_id', $data['supplier_id'])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'supplier_id' => 'This supplier is already mapped to the selected '.$config['singular'].'.',
            ]);
        }

        return $data;
    }

    /**
     * @param array<string, string> $config
     * @param array<string, mixed> $data
     */
    private function persist(array $config, array $data, ?int $mappingId = null): int
    {
        return DB::transaction(function () use ($config, $data, $mappingId): int {
            if ($data['is_preferred']) {
                DB::table($config['mapping_table'])
                    ->where($config['item_column'], $data['item_id'])
                    ->when($mappingId, fn ($query) => $query->where('id', '!=', $mappingId))
                    ->update(['is_preferred' => false, 'updated_at' => now()]);
            }

            $values = [
                $config['item_column'] => $data['item_id'],
                'supplier_id' => $data['supplier_id'],
                'supplier_code' => $data['supplier_code'],
                'lead_days' => $data['lead_days'],
                'is_preferred' => $data['is_preferred'],
                'updated_at' => now(),
            ];

            if ($mappingId) {
                DB::table($config['mapping_table'])->where('id', $mappingId)->update($values);

                return $mappingId;
            }

            $values['created_at'] = now();

            return DB::table($config['mapping_table'])->insertGetId($values);
        });
    }

    /** @param array<string, string> $config */
    private function mapping(array $config, int $mapping): object
    {
        $record = $this->mappingQuery($config)
            ->where('m.id', $mapping)
            ->first();

        abort_unless($record, 404);

        return $record;
    }
}
