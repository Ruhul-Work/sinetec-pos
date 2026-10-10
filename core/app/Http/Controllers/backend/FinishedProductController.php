<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\FinishedProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class FinishedProductController extends Controller
{
    public function index(): View
    {
        return view('backend.modules.finished_product.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = [
            'id',
            'image_path',
            'product_code',
            'name',
            'finished_product_category_id',
            'base_unit_id',
            'brand_id',
            'retail_price',
            'wholesale_price',
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
        $status = $request->input('status');

        $query = FinishedProduct::query()
            ->with(['category:id,name,code', 'baseUnit:id,name,code', 'brand:id,name'])
            ->select([
                'id', 'product_code', 'barcode', 'name', 'finished_product_category_id',
                'base_unit_id', 'brand_id', 'retail_price', 'wholesale_price',
                'image_path', 'is_active', 'created_at',
            ]);

        $total = (clone $query)->count();

        if (in_array((string) $status, ['0', '1'], true)) {
            $query->where('is_active', (int) $status);
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('product_code', 'like', "%{$search}%")
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
        $canEdit = $user?->canDo('inventory.finished-products.update', 'edit') ?? false;
        $canDelete = $user?->canDo('inventory.finished-products.destroy', 'delete') ?? false;

        $data = $rows->map(function (FinishedProduct $product) use ($canEdit, $canDelete) {
            $image = $product->image_path
                ? '<img src="'.e(image($product->image_path)).'" alt="" class="rounded-8 border" style="width:42px;height:42px;object-fit:cover">'
                : '<span class="w-40-px h-40-px rounded-8 bg-neutral-100 d-inline-flex align-items-center justify-content-center text-neutral-500"><iconify-icon icon="mdi:image-off-outline"></iconify-icon></span>';
            $status = $product->is_active
                ? '<span class="badge text-sm fw-semibold bg-dark-success-gradient px-20 py-9 radius-4 text-white">Active</span>'
                : '<span class="badge text-sm fw-semibold bg-dark-warning-gradient px-20 py-9 radius-4 text-white">Inactive</span>';
            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">';

            if ($canEdit) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main AjaxModal"'
                    .' data-ajax-modal="'.e(route('inventory.finished-products.editModal', $product->id)).'"'
                    .' data-size="lg" data-onload="FinishedProductsIndex.onLoad" data-onsuccess="FinishedProductsIndex.onSaved" title="Edit">'
                    .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }

            if ($canDelete) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-finished-product-delete"'
                    .' data-url="'.e(route('inventory.finished-products.destroy', $product->id)).'" title="Delete">'
                    .'<iconify-icon icon="mdi:delete"></iconify-icon></a>';
            }

            $actions .= '</div>';

            return [
                $product->id,
                $image,
                '<code>'.e($product->product_code).'</code>',
                '<strong>'.e($product->name).'</strong>'.($product->barcode ? '<div class="text-muted text-xs">'.e($product->barcode).'</div>' : ''),
                e($product->category?->name ?? '—'),
                e($product->baseUnit?->name ?? '—').($product->baseUnit?->code ? ' <small class="text-muted">('.e($product->baseUnit->code).')</small>' : ''),
                e($product->brand?->name ?? '—'),
                $this->formatPrice($product->retail_price),
                $this->formatPrice($product->wholesale_price),
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
        return view('backend.modules.finished_product.modal', [
            'product' => null,
            'action' => route('inventory.finished-products.store'),
            'method' => 'POST',
            'title' => 'Add Finished Product',
        ]);
    }

    public function editModal(FinishedProduct $finishedProduct): View
    {
        $finishedProduct->load(['category:id,name,code', 'baseUnit:id,name,code', 'brand:id,name']);

        return view('backend.modules.finished_product.modal', [
            'product' => $finishedProduct,
            'action' => route('inventory.finished-products.update', $finishedProduct),
            'method' => 'PUT',
            'title' => 'Edit Finished Product',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $newImage = null;

        if ($request->hasFile('image')) {
            $newImage = uploadImage($request->file('image'), 'finished-products');
            $data['image_path'] = $newImage;
        }

        try {
            $product = FinishedProduct::create($data);
        } catch (Throwable $exception) {
            $this->deleteManagedImage($newImage);
            throw $exception;
        }

        return response()->json([
            'success' => true,
            'msg' => 'Finished product created successfully.',
            'id' => $product->id,
        ], 201);
    }

    public function update(Request $request, FinishedProduct $finishedProduct): JsonResponse
    {
        $data = $this->validated($request, $finishedProduct);
        $oldImage = $finishedProduct->image_path;
        $newImage = null;
        $removeOldImage = $request->boolean('remove_image');

        if ($request->hasFile('image')) {
            $newImage = uploadImage($request->file('image'), 'finished-products');
            $data['image_path'] = $newImage;
            $removeOldImage = true;
        } elseif ($removeOldImage) {
            $data['image_path'] = null;
        }

        try {
            $finishedProduct->update($data);
        } catch (Throwable $exception) {
            $this->deleteManagedImage($newImage);
            throw $exception;
        }

        if ($removeOldImage && $oldImage !== $newImage) {
            $this->deleteManagedImage($oldImage);
        }

        return response()->json(['success' => true, 'msg' => 'Finished product updated successfully.']);
    }

    public function destroy(FinishedProduct $finishedProduct): JsonResponse
    {
        $references = [
            ['finished_product_units', 'finished_product_id'],
            ['finished_product_stock_movements', 'finished_product_id'],
            ['finished_product_stock_balances', 'finished_product_id'],
            ['finished_product_reorder_levels', 'finished_product_id'],
            ['finished_product_transfer_items', 'finished_product_id'],
            ['finished_product_adjustment_items', 'finished_product_id'],
            ['product_boms', 'finished_product_id'],
            ['sale_items', 'finished_product_id'],
            ['sale_return_items', 'finished_product_id'],
            ['monthly_finished_product_rates', 'finished_product_id'],
            ['demand_plan_items', 'finished_product_id'],
            ['offer_finished_products', 'finished_product_id'],
            ['coupon_finished_products', 'finished_product_id'],
        ];

        foreach ($references as [$table, $column]) {
            if (DB::table($table)->where($column, $finishedProduct->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'msg' => 'This finished product already has inventory, BOM, sales, rate, planning or promotion references and cannot be deleted. Deactivate it instead.',
                ], 422);
            }
        }

        $finishedProduct->delete();

        return response()->json(['success' => true, 'msg' => 'Finished product deleted successfully.']);
    }

    public function select2(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $query = FinishedProduct::query()->where('is_active', true);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('product_code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $results = $query->orderBy('name')->limit(20)
            ->get(['id', 'name', 'product_code'])
            ->map(fn (FinishedProduct $product) => [
                'id' => $product->id,
                'text' => "{$product->name} ({$product->product_code})",
            ]);

        return response()->json(['results' => $results]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?FinishedProduct $product = null): array
    {
        $request->merge([
            'product_code' => strtoupper(trim((string) $request->input('product_code'))),
            'barcode' => filled($request->input('barcode')) ? trim((string) $request->input('barcode')) : null,
            'name' => trim((string) $request->input('name')),
            'retail_price' => filled($request->input('retail_price')) ? $request->input('retail_price') : null,
            'wholesale_price' => filled($request->input('wholesale_price')) ? $request->input('wholesale_price') : null,
        ]);

        $data = $request->validate([
            'product_code' => [
                'required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('finished_products', 'product_code')->ignore($product?->id),
            ],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('finished_products', 'barcode')->ignore($product?->id)],
            'name' => ['required', 'string', 'max:200'],
            'finished_product_category_id' => [
                'required', 'integer',
                Rule::exists('finished_product_categories', 'id')
                    ->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'base_unit_id' => [
                'required', 'integer',
                Rule::exists('units', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'brand_id' => [
                'nullable', 'integer',
                Rule::exists('brands', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'retail_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.9999'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.9999'],
            'specification' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'remove_image' => ['sometimes', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ], [
            'product_code.regex' => 'Product code may contain only uppercase letters, numbers, dots, underscores and hyphens.',
        ]);

        unset($data['image'], $data['remove_image']);
        $data['brand_id'] = $data['brand_id'] ?? null;
        $data['barcode'] = $data['barcode'] ?? null;
        $data['retail_price'] = $data['retail_price'] ?? null;
        $data['wholesale_price'] = $data['wholesale_price'] ?? null;
        $data['specification'] = filled($data['specification'] ?? null) ? trim($data['specification']) : null;
        $data['description'] = filled($data['description'] ?? null) ? trim($data['description']) : null;
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function formatPrice(mixed $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 2, '.', ',');
    }

    private function deleteManagedImage(?string $path): void
    {
        if (! $path) {
            return;
        }

        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
        if (! str_starts_with($relativePath, 'uploads/finished-products/')) {
            return;
        }

        File::delete(base_path('../'.$relativePath));
    }
}
