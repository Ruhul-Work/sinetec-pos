<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\RawMaterial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class RawMaterialController extends Controller
{
    private const KINDS = ['MATERIAL', 'PACKAGING', 'CONSUMABLE'];

    public function index(): View
    {
        return view('backend.modules.raw_material.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = [
            'id',
            'image_path',
            'material_code',
            'name',
            'raw_material_category_id',
            'material_kind',
            'base_unit_id',
            'brand_id',
            'is_active',
            'created_at',
        ];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));
        $kind = strtoupper(trim((string) $request->input('material_kind', '')));
        $status = $request->input('status');

        $query = RawMaterial::query()
            ->with([
                'category:id,name,code',
                'baseUnit:id,name,code',
                'brand:id,name',
            ])
            ->select([
                'id',
                'material_code',
                'barcode',
                'name',
                'raw_material_category_id',
                'base_unit_id',
                'brand_id',
                'material_kind',
                'image_path',
                'is_active',
                'created_at',
            ]);

        $total = (clone $query)->count();

        if (in_array($kind, self::KINDS, true)) {
            $query->where('material_kind', $kind);
        }

        if (in_array((string) $status, ['0', '1'], true)) {
            $query->where('is_active', (int) $status);
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('material_code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('category', fn (Builder $category) => $category
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%"))
                    ->orWhereHas('baseUnit', fn (Builder $unit) => $unit
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%"))
                    ->orWhereHas('brand', fn (Builder $brand) => $brand
                        ->where('name', 'like', "%{$search}%"));
            });
        }

        $filtered = (clone $query)->count();
        $orderColumn = $columns[$orderIndex] ?? 'id';
        $rows = $query->orderBy($orderColumn, $orderDirection)
            ->skip($start)
            ->take($length)
            ->get();

        $user = $request->user();
        $canEdit = $user?->canDo('inventory.raw-materials.update', 'edit') ?? false;
        $canDelete = $user?->canDo('inventory.raw-materials.destroy', 'delete') ?? false;

        $data = $rows->map(function (RawMaterial $material) use ($canEdit, $canDelete) {
            $image = $material->image_path
                ? '<img src="'.e(image($material->image_path)).'" alt="" class="rounded-8 border" style="width:42px;height:42px;object-fit:cover">'
                : '<span class="w-40-px h-40-px rounded-8 bg-neutral-100 d-inline-flex align-items-center justify-content-center text-neutral-500"><iconify-icon icon="mdi:image-off-outline"></iconify-icon></span>';

            $kindClasses = match ($material->material_kind) {
                'PACKAGING' => 'bg-dark-info-gradient',
                'CONSUMABLE' => 'bg-dark-warning-gradient',
                default => 'bg-dark-primary-gradient',
            };
            $kind = '<span class="badge text-sm fw-semibold '.$kindClasses.' px-12 py-7 radius-4 text-white">'
                .e(ucfirst(strtolower($material->material_kind))).'</span>';
            $status = $material->is_active
                ? '<span class="badge text-sm fw-semibold bg-dark-success-gradient px-20 py-9 radius-4 text-white">Active</span>'
                : '<span class="badge text-sm fw-semibold bg-dark-warning-gradient px-20 py-9 radius-4 text-white">Inactive</span>';

            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">';

            if ($canEdit) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main AjaxModal"'
                    .' data-ajax-modal="'.e(route('inventory.raw-materials.editModal', $material->id)).'"'
                    .' data-size="lg" data-onload="RawMaterialsIndex.onLoad" data-onsuccess="RawMaterialsIndex.onSaved" title="Edit">'
                    .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }

            if ($canDelete) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-raw-material-delete"'
                    .' data-url="'.e(route('inventory.raw-materials.destroy', $material->id)).'" title="Delete">'
                    .'<iconify-icon icon="mdi:delete"></iconify-icon></a>';
            }

            $actions .= '</div>';

            return [
                $material->id,
                $image,
                '<code>'.e($material->material_code).'</code>',
                '<strong>'.e($material->name).'</strong>'
                    .($material->barcode ? '<div class="text-muted text-xs">'.e($material->barcode).'</div>' : ''),
                e($material->category?->name ?? '—'),
                $kind,
                e($material->baseUnit?->name ?? '—')
                    .($material->baseUnit?->code ? ' <small class="text-muted">('.e($material->baseUnit->code).')</small>' : ''),
                e($material->brand?->name ?? '—'),
                $status,
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
        return view('backend.modules.raw_material.modal', [
            'material' => null,
            'kinds' => self::KINDS,
            'action' => route('inventory.raw-materials.store'),
            'method' => 'POST',
            'title' => 'Add Raw Material',
        ]);
    }

    public function editModal(RawMaterial $rawMaterial): View
    {
        $rawMaterial->load(['category:id,name,code', 'baseUnit:id,name,code', 'brand:id,name']);

        return view('backend.modules.raw_material.modal', [
            'material' => $rawMaterial,
            'kinds' => self::KINDS,
            'action' => route('inventory.raw-materials.update', $rawMaterial),
            'method' => 'PUT',
            'title' => 'Edit Raw Material',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $newImage = null;

        if ($request->hasFile('image')) {
            $newImage = uploadImage($request->file('image'), 'raw-materials');
            $data['image_path'] = $newImage;
        }

        try {
            $material = RawMaterial::create($data);
        } catch (Throwable $exception) {
            $this->deleteManagedImage($newImage);
            throw $exception;
        }

        return response()->json([
            'success' => true,
            'msg' => 'Raw material created successfully.',
            'id' => $material->id,
        ], 201);
    }

    public function update(Request $request, RawMaterial $rawMaterial): JsonResponse
    {
        $data = $this->validated($request, $rawMaterial);
        $oldImage = $rawMaterial->image_path;
        $newImage = null;
        $removeOldImage = $request->boolean('remove_image');

        if ($request->hasFile('image')) {
            $newImage = uploadImage($request->file('image'), 'raw-materials');
            $data['image_path'] = $newImage;
            $removeOldImage = true;
        } elseif ($removeOldImage) {
            $data['image_path'] = null;
        }

        try {
            $rawMaterial->update($data);
        } catch (Throwable $exception) {
            $this->deleteManagedImage($newImage);
            throw $exception;
        }

        if ($removeOldImage && $oldImage !== $newImage) {
            $this->deleteManagedImage($oldImage);
        }

        return response()->json([
            'success' => true,
            'msg' => 'Raw material updated successfully.',
        ]);
    }

    public function destroy(RawMaterial $rawMaterial): JsonResponse
    {
        $references = [
            ['raw_material_units', 'raw_material_id'],
            ['raw_material_suppliers', 'raw_material_id'],
            ['purchase_order_item', 'raw_material_id'],
            ['purchase_receipt_item', 'raw_material_id'],
            ['purchase_return_item', 'raw_material_id'],
            ['raw_material_stock_movements', 'raw_material_id'],
            ['raw_material_stock_balances', 'raw_material_id'],
            ['raw_material_reorder_levels', 'raw_material_id'],
            ['raw_material_transfer_items', 'raw_material_id'],
            ['raw_material_adjustment_items', 'raw_material_id'],
            ['part_bom_items', 'raw_material_id'],
            ['part_production_consumptions', 'raw_material_id'],
            ['product_bom_items', 'raw_material_id'],
            ['product_assembly_consumptions', 'raw_material_id'],
            ['monthly_raw_material_rates', 'raw_material_id'],
            ['raw_material_requirement_items', 'raw_material_id'],
        ];

        foreach ($references as [$table, $column]) {
            if (DB::table($table)->where($column, $rawMaterial->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'msg' => 'This raw material already has inventory, purchase, BOM, production, rate or planning references and cannot be deleted. Deactivate it instead.',
                ], 422);
            }
        }

        // Keep the image while the soft-deleted master remains recoverable/auditable.
        $rawMaterial->delete();

        return response()->json([
            'success' => true,
            'msg' => 'Raw material deleted successfully.',
        ]);
    }

    public function select2(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $query = RawMaterial::query()->where('is_active', true);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('material_code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $results = $query->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'material_code'])
            ->map(fn (RawMaterial $material) => [
                'id' => $material->id,
                'text' => "{$material->name} ({$material->material_code})",
            ]);

        return response()->json(['results' => $results]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?RawMaterial $material = null): array
    {
        $request->merge([
            'material_code' => strtoupper(trim((string) $request->input('material_code'))),
            'barcode' => filled($request->input('barcode')) ? trim((string) $request->input('barcode')) : null,
            'name' => trim((string) $request->input('name')),
            'material_kind' => strtoupper(trim((string) $request->input('material_kind'))),
        ]);

        $data = $request->validate([
            'material_code' => [
                'required',
                'string',
                'max:80',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('raw_materials', 'material_code')->ignore($material?->id),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('raw_materials', 'barcode')->ignore($material?->id),
            ],
            'name' => ['required', 'string', 'max:200'],
            'raw_material_category_id' => [
                'required',
                'integer',
                Rule::exists('raw_material_categories', 'id')
                    ->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'base_unit_id' => [
                'required',
                'integer',
                Rule::exists('units', 'id')
                    ->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'brand_id' => [
                'nullable',
                'integer',
                Rule::exists('brands', 'id')
                    ->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'material_kind' => ['required', Rule::in(self::KINDS)],
            'specification' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'remove_image' => ['sometimes', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ], [
            'material_code.regex' => 'Material code may contain only uppercase letters, numbers, dots, underscores and hyphens.',
        ]);

        unset($data['image'], $data['remove_image']);
        $data['brand_id'] = $data['brand_id'] ?? null;
        $data['barcode'] = $data['barcode'] ?? null;
        $data['specification'] = filled($data['specification'] ?? null) ? trim($data['specification']) : null;
        $data['description'] = filled($data['description'] ?? null) ? trim($data['description']) : null;
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function deleteManagedImage(?string $path): void
    {
        if (! $path) {
            return;
        }

        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
        if (! str_starts_with($relativePath, 'uploads/raw-materials/')) {
            return;
        }

        File::delete(base_path('../'.$relativePath));
    }
}
