<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\Part;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class PartController extends Controller
{
    private const PROCUREMENT_MODES = ['MAKE', 'BUY', 'BOTH'];

    public function index(): View
    {
        return view('backend.modules.part.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = [
            'id',
            'image_path',
            'part_code',
            'name',
            'part_category_id',
            'procurement_mode',
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
        $procurementMode = strtoupper(trim((string) $request->input('procurement_mode', '')));
        $status = $request->input('status');

        $query = Part::query()
            ->with([
                'category:id,name,code',
                'baseUnit:id,name,code',
                'brand:id,name',
            ])
            ->select([
                'id',
                'part_code',
                'barcode',
                'name',
                'part_category_id',
                'base_unit_id',
                'brand_id',
                'procurement_mode',
                'image_path',
                'is_active',
                'created_at',
            ]);

        $total = (clone $query)->count();

        if (in_array($procurementMode, self::PROCUREMENT_MODES, true)) {
            $query->where('procurement_mode', $procurementMode);
        }

        if (in_array((string) $status, ['0', '1'], true)) {
            $query->where('is_active', (int) $status);
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('part_code', 'like', "%{$search}%")
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
        $canEdit = $user?->canDo('inventory.parts.update', 'edit') ?? false;
        $canDelete = $user?->canDo('inventory.parts.destroy', 'delete') ?? false;

        $data = $rows->map(function (Part $part) use ($canEdit, $canDelete) {
            $image = $part->image_path
                ? '<img src="'.e(image($part->image_path)).'" alt="" class="rounded-8 border" style="width:42px;height:42px;object-fit:cover">'
                : '<span class="w-40-px h-40-px rounded-8 bg-neutral-100 d-inline-flex align-items-center justify-content-center text-neutral-500"><iconify-icon icon="mdi:image-off-outline"></iconify-icon></span>';

            $modeClasses = match ($part->procurement_mode) {
                'MAKE' => 'bg-dark-primary-gradient',
                'BUY' => 'bg-dark-info-gradient',
                default => 'bg-dark-success-gradient',
            };
            $mode = '<span class="badge text-sm fw-semibold '.$modeClasses.' px-12 py-7 radius-4 text-white">'
                .e(ucfirst(strtolower($part->procurement_mode))).'</span>';
            $status = $part->is_active
                ? '<span class="badge text-sm fw-semibold bg-dark-success-gradient px-20 py-9 radius-4 text-white">Active</span>'
                : '<span class="badge text-sm fw-semibold bg-dark-warning-gradient px-20 py-9 radius-4 text-white">Inactive</span>';

            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">';

            if ($canEdit) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main AjaxModal"'
                    .' data-ajax-modal="'.e(route('inventory.parts.editModal', $part->id)).'"'
                    .' data-size="lg" data-onload="PartsIndex.onLoad" data-onsuccess="PartsIndex.onSaved" title="Edit">'
                    .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }

            if ($canDelete) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-part-delete"'
                    .' data-url="'.e(route('inventory.parts.destroy', $part->id)).'" title="Delete">'
                    .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
            }

            $actions .= '</div>';

            return [
                $part->id,
                $image,
                '<code>'.e($part->part_code).'</code>',
                '<strong>'.e($part->name).'</strong>'
                    .($part->barcode ? '<div class="text-muted text-xs">'.e($part->barcode).'</div>' : ''),
                e($part->category?->name ?? '—'),
                $mode,
                e($part->baseUnit?->name ?? '—')
                    .($part->baseUnit?->code ? ' <small class="text-muted">('.e($part->baseUnit->code).')</small>' : ''),
                e($part->brand?->name ?? '—'),
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
        return view('backend.modules.part.modal', [
            'part' => null,
            'procurementModes' => self::PROCUREMENT_MODES,
            'action' => route('inventory.parts.store'),
            'method' => 'POST',
            'title' => 'Add Part',
        ]);
    }

    public function editModal(Part $part): View
    {
        $part->load(['category:id,name,code', 'baseUnit:id,name,code', 'brand:id,name']);

        return view('backend.modules.part.modal', [
            'part' => $part,
            'procurementModes' => self::PROCUREMENT_MODES,
            'action' => route('inventory.parts.update', $part),
            'method' => 'PUT',
            'title' => 'Edit Part',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $newImage = null;

        if ($request->hasFile('image')) {
            $newImage = uploadImage($request->file('image'), 'parts');
            $data['image_path'] = $newImage;
        }

        try {
            $part = Part::create($data);
        } catch (Throwable $exception) {
            $this->deleteManagedImage($newImage);
            throw $exception;
        }

        return response()->json([
            'success' => true,
            'msg' => 'Part created successfully.',
            'id' => $part->id,
        ], 201);
    }

    public function update(Request $request, Part $part): JsonResponse
    {
        $data = $this->validated($request, $part);
        $oldImage = $part->image_path;
        $newImage = null;
        $removeOldImage = $request->boolean('remove_image');

        if ($request->hasFile('image')) {
            $newImage = uploadImage($request->file('image'), 'parts');
            $data['image_path'] = $newImage;
            $removeOldImage = true;
        } elseif ($removeOldImage) {
            $data['image_path'] = null;
        }

        try {
            $part->update($data);
        } catch (Throwable $exception) {
            $this->deleteManagedImage($newImage);
            throw $exception;
        }

        if ($removeOldImage && $oldImage !== $newImage) {
            $this->deleteManagedImage($oldImage);
        }

        return response()->json([
            'success' => true,
            'msg' => 'Part updated successfully.',
        ]);
    }

    public function destroy(Part $part): JsonResponse
    {
        $references = [
            ['part_units', 'part_id'],
            ['part_suppliers', 'part_id'],
            ['purchase_order_item', 'part_id'],
            ['purchase_receipt_item', 'part_id'],
            ['purchase_return_item', 'part_id'],
            ['part_stock_movements', 'part_id'],
            ['part_stock_balances', 'part_id'],
            ['part_reorder_levels', 'part_id'],
            ['part_transfer_items', 'part_id'],
            ['part_adjustment_items', 'part_id'],
            ['part_boms', 'part_id'],
            ['product_bom_items', 'part_id'],
            ['product_assembly_consumptions', 'part_id'],
            ['monthly_part_rates', 'part_id'],
            ['part_requirement_items', 'part_id'],
        ];

        foreach ($references as [$table, $column]) {
            if (DB::table($table)->where($column, $part->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'msg' => 'This part already has purchase, inventory, BOM, production, rate or planning references and cannot be deleted. Deactivate it instead.',
                ], 422);
            }
        }

        // Keep the image while the soft-deleted master remains recoverable/auditable.
        $part->delete();

        return response()->json([
            'success' => true,
            'msg' => 'Part deleted successfully.',
        ]);
    }

    public function select2(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $query = Part::query()->where('is_active', true);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('part_code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $results = $query->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'part_code'])
            ->map(fn (Part $part) => [
                'id' => $part->id,
                'text' => "{$part->name} ({$part->part_code})",
            ]);

        return response()->json(['results' => $results]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Part $part = null): array
    {
        $request->merge([
            'part_code' => strtoupper(trim((string) $request->input('part_code'))),
            'barcode' => filled($request->input('barcode')) ? trim((string) $request->input('barcode')) : null,
            'name' => trim((string) $request->input('name')),
            'procurement_mode' => strtoupper(trim((string) $request->input('procurement_mode'))),
        ]);

        $data = $request->validate([
            'part_code' => [
                'required',
                'string',
                'max:80',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('parts', 'part_code')->ignore($part?->id),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('parts', 'barcode')->ignore($part?->id),
            ],
            'name' => ['required', 'string', 'max:200'],
            'part_category_id' => [
                'required',
                'integer',
                Rule::exists('part_categories', 'id')
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
            'procurement_mode' => ['required', Rule::in(self::PROCUREMENT_MODES)],
            'specification' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'remove_image' => ['sometimes', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ], [
            'part_code.regex' => 'Part code may contain only uppercase letters, numbers, dots, underscores and hyphens.',
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
        if (! str_starts_with($relativePath, 'uploads/parts/')) {
            return;
        }

        File::delete(base_path('../'.$relativePath));
    }
}
