<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DomainUnitMappingController extends Controller
{
    private const DOMAINS = [
        'raw-materials' => [
            'label' => 'Raw Materials',
            'singular' => 'Raw Material',
            'icon' => 'mdi:flask-outline',
            'mapping_table' => 'raw_material_units',
            'item_table' => 'raw_materials',
            'item_column' => 'raw_material_id',
            'code_column' => 'material_code',
            'item_select_route' => 'inventory.raw-materials.select2',
            'usages' => ['PURCHASE', 'PRODUCTION', 'ALL'],
        ],
        'parts' => [
            'label' => 'Parts',
            'singular' => 'Part',
            'icon' => 'mdi:cog-outline',
            'mapping_table' => 'part_units',
            'item_table' => 'parts',
            'item_column' => 'part_id',
            'code_column' => 'part_code',
            'item_select_route' => 'inventory.parts.select2',
            'usages' => ['PURCHASE', 'PRODUCTION', 'ALL'],
        ],
        'finished-products' => [
            'label' => 'Finished Products',
            'singular' => 'Finished Product',
            'icon' => 'mdi:package-variant-closed-check',
            'mapping_table' => 'finished_product_units',
            'item_table' => 'finished_products',
            'item_column' => 'finished_product_id',
            'code_column' => 'product_code',
            'item_select_route' => 'inventory.finished-products.select2',
            'usages' => ['PRODUCTION', 'SALE', 'ALL'],
        ],
    ];

    public function index(string $domain): View
    {
        $config = $this->domain($domain);

        return view('backend.modules.domain_unit.index', [
            'domain' => $domain,
            'config' => $config,
            'domains' => self::DOMAINS,
        ]);
    }

    public function listAjax(Request $request, string $domain): JsonResponse
    {
        $config = $this->domain($domain);
        $mappingTable = $config['mapping_table'];
        $itemTable = $config['item_table'];
        $itemColumn = $config['item_column'];
        $codeColumn = $config['code_column'];
        $columns = ['m.id', 'i.name', 'i.base_unit_id', 'm.unit_id', 'm.conversion_to_base', 'm.usage_type', 'm.updated_at'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));
        $usage = strtoupper(trim((string) $request->input('usage_type', '')));

        $query = DB::table($mappingTable.' as m')
            ->join($itemTable.' as i', 'i.id', '=', 'm.'.$itemColumn)
            ->join('units as u', 'u.id', '=', 'm.unit_id')
            ->join('units as bu', 'bu.id', '=', 'i.base_unit_id')
            ->whereNull('i.deleted_at')
            ->select([
                'm.id', 'm.conversion_to_base', 'm.usage_type', 'm.updated_at',
                'i.id as item_id', 'i.name as item_name', 'i.'.$codeColumn.' as item_code',
                'u.id as unit_id', 'u.name as unit_name', 'u.code as unit_code',
                'bu.id as base_unit_id', 'bu.name as base_unit_name', 'bu.code as base_unit_code',
            ]);

        $total = (clone $query)->count();

        if (in_array($usage, $config['usages'], true)) {
            $query->where('m.usage_type', $usage);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search, $codeColumn): void {
                $builder->where('i.name', 'like', "%{$search}%")
                    ->orWhere('i.'.$codeColumn, 'like', "%{$search}%")
                    ->orWhere('u.name', 'like', "%{$search}%")
                    ->orWhere('u.code', 'like', "%{$search}%")
                    ->orWhere('bu.name', 'like', "%{$search}%")
                    ->orWhere('bu.code', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $orderColumn = $columns[$orderIndex] ?? 'm.id';
        $rows = $query->orderBy($orderColumn, $orderDirection)
            ->skip($start)
            ->take($length)
            ->get();

        $user = $request->user();
        $canEdit = $user?->canDo('inventory.item-units.update', 'edit') ?? false;
        $canDelete = $user?->canDo('inventory.item-units.destroy', 'delete') ?? false;

        $data = $rows->map(function ($mapping) use ($domain, $canEdit, $canDelete): array {
            $alternativeCode = e($mapping->unit_code);
            $baseCode = e($mapping->base_unit_code);
            $factor = $this->formatDecimal($mapping->conversion_to_base);
            $usageClass = match ($mapping->usage_type) {
                'PURCHASE' => 'bg-dark-info-gradient',
                'PRODUCTION' => 'bg-dark-primary-gradient',
                'SALE' => 'bg-dark-warning-gradient',
                default => 'bg-dark-success-gradient',
            };
            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">';

            if ($canEdit) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main AjaxModal"'
                    .' data-ajax-modal="'.e(route('inventory.item-units.editModal', ['domain' => $domain, 'mapping' => $mapping->id])).'"'
                    .' data-size="md" data-onload="DomainUnitsIndex.onLoad" data-onsuccess="DomainUnitsIndex.onSaved" title="Edit">'
                    .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }

            if ($canDelete) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-domain-unit-delete"'
                    .' data-url="'.e(route('inventory.item-units.destroy', ['domain' => $domain, 'mapping' => $mapping->id])).'" title="Delete">'
                    .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
            }

            $actions .= '</div>';

            return [
                $mapping->id,
                '<strong>'.e($mapping->item_name).'</strong><br><code>'.e($mapping->item_code).'</code>',
                '<span class="fw-medium">'.e($mapping->base_unit_name).'</span><br><code>'.$baseCode.'</code>',
                '<span class="fw-medium">'.e($mapping->unit_name).'</span><br><code>'.$alternativeCode.'</code>',
                '<span class="fw-semibold">1 '.$alternativeCode.' = '.$factor.' '.$baseCode.'</span>',
                '<span class="badge text-sm fw-semibold '.$usageClass.' px-12 py-7 radius-4 text-white">'.e($this->usageLabel($mapping->usage_type)).'</span>',
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

    public function createModal(string $domain): View
    {
        return $this->modalView($domain, null, route('inventory.item-units.store', $domain), 'POST');
    }

    public function store(Request $request, string $domain): JsonResponse
    {
        $config = $this->domain($domain);
        $data = $this->validated($request, $config);

        $id = DB::table($config['mapping_table'])->insertGetId([
            $config['item_column'] => $data['item_id'],
            'unit_id' => $data['unit_id'],
            'conversion_to_base' => $data['conversion_to_base'],
            'usage_type' => $data['usage_type'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'msg' => 'Item unit mapping created successfully.', 'id' => $id], 201);
    }

    public function editModal(string $domain, int $mapping): View
    {
        $config = $this->domain($domain);
        $record = $this->mapping($config, $mapping);

        return $this->modalView(
            $domain,
            $record,
            route('inventory.item-units.update', ['domain' => $domain, 'mapping' => $mapping]),
            'PUT'
        );
    }

    public function update(Request $request, string $domain, int $mapping): JsonResponse
    {
        $config = $this->domain($domain);
        $this->mapping($config, $mapping);
        $data = $this->validated($request, $config, $mapping);

        DB::table($config['mapping_table'])->where('id', $mapping)->update([
            $config['item_column'] => $data['item_id'],
            'unit_id' => $data['unit_id'],
            'conversion_to_base' => $data['conversion_to_base'],
            'usage_type' => $data['usage_type'],
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'msg' => 'Item unit mapping updated successfully.']);
    }

    public function destroy(string $domain, int $mapping): JsonResponse
    {
        $config = $this->domain($domain);
        $this->mapping($config, $mapping);
        DB::table($config['mapping_table'])->where('id', $mapping)->delete();

        return response()->json(['success' => true, 'msg' => 'Item unit mapping deleted successfully.']);
    }

    public function itemBaseUnit(string $domain, int $item): JsonResponse
    {
        $config = $this->domain($domain);
        $record = DB::table($config['item_table'].' as i')
            ->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->where('i.id', $item)
            ->whereNull('i.deleted_at')
            ->select(['u.id', 'u.name', 'u.code'])
            ->first();

        abort_unless($record, 404);

        return response()->json(['base_unit' => $record]);
    }

    private function modalView(string $domain, ?object $mapping, string $action, string $method): View
    {
        $config = $this->domain($domain);

        if ($mapping) {
            $mapping->item_text = $mapping->item_name.' ('.$mapping->item_code.')';
            $mapping->unit_text = $mapping->unit_name.' ('.$mapping->unit_code.')';
            $mapping->base_unit_text = $mapping->base_unit_name.' ('.$mapping->base_unit_code.')';
        }

        return view('backend.modules.domain_unit.modal', [
            'domain' => $domain,
            'config' => $config,
            'mapping' => $mapping,
            'action' => $action,
            'method' => $method,
            'title' => ($mapping ? 'Edit ' : 'Add ').$config['singular'].' Unit',
        ]);
    }

    private function validated(Request $request, array $config, ?int $mappingId = null): array
    {
        $data = $request->validate([
            'item_id' => [
                'required', 'integer',
                Rule::exists($config['item_table'], 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'unit_id' => [
                'required', 'integer',
                Rule::exists('units', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'conversion_to_base' => ['required', 'numeric', 'gte:0.000001', 'max:99999999999999.999999'],
            'usage_type' => ['required', Rule::in($config['usages'])],
        ]);

        $item = DB::table($config['item_table'])
            ->where('id', $data['item_id'])
            ->whereNull('deleted_at')
            ->first(['base_unit_id']);

        if ($item && (int) $item->base_unit_id === (int) $data['unit_id']) {
            throw ValidationException::withMessages([
                'unit_id' => 'The base unit is already available with factor 1. Select an alternative unit.',
            ]);
        }

        $duplicate = DB::table($config['mapping_table'])
            ->when($mappingId, fn ($query) => $query->where('id', '!=', $mappingId))
            ->where($config['item_column'], $data['item_id'])
            ->where('unit_id', $data['unit_id'])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'unit_id' => 'This item already has a mapping for the selected unit.',
            ]);
        }

        return $data;
    }

    private function mapping(array $config, int $mapping): object
    {
        $record = DB::table($config['mapping_table'].' as m')
            ->join($config['item_table'].' as i', 'i.id', '=', 'm.'.$config['item_column'])
            ->join('units as u', 'u.id', '=', 'm.unit_id')
            ->join('units as bu', 'bu.id', '=', 'i.base_unit_id')
            ->where('m.id', $mapping)
            ->select([
                'm.*', 'm.'.$config['item_column'].' as item_id',
                'i.name as item_name', 'i.'.$config['code_column'].' as item_code',
                'u.name as unit_name', 'u.code as unit_code',
                'bu.name as base_unit_name', 'bu.code as base_unit_code',
            ])
            ->first();

        abort_unless($record, 404);

        return $record;
    }

    private function domain(string $domain): array
    {
        abort_unless(isset(self::DOMAINS[$domain]), 404);

        return self::DOMAINS[$domain];
    }

    private function formatDecimal(string|float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }

    private function usageLabel(string $usage): string
    {
        return $usage === 'ALL' ? 'All Applicable' : ucfirst(strtolower($usage));
    }
}
