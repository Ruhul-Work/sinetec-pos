<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\FinishedProduct;
use App\Models\backend\Part;
use App\Models\backend\RawMaterial;
use App\Models\backend\Unit;
use App\Models\Production\ProductBom;
use App\Services\Production\ProductBomService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductBomController extends Controller
{
    public function __construct(
        private readonly ProductBomService $productBomService,
    ) {}

    public function index(): View
    {
        return view('backend.modules.product_bom.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = [
            'id',
            'finished_product_id',
            'version',
            'output_quantity',
            'items_count',
            'effective_from',
            'status',
        ];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc'
            ? 'asc'
            : 'desc';
        $status = strtoupper(trim((string) $request->input('status', '')));
        $search = trim((string) $request->input('search.value', ''));

        $query = ProductBom::query()
            ->with([
                'finishedProduct:id,product_code,name,base_unit_id',
                'finishedProduct.baseUnit:id,name,code',
            ])
            ->withCount('items');

        $total = (clone $query)->count();

        if (in_array($status, ['DRAFT', 'ACTIVE', 'INACTIVE'], true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('version', 'like', "%{$search}%")
                    ->orWhereHas(
                        'finishedProduct',
                        fn (Builder $product) => $product
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('product_code', 'like', "%{$search}%"),
                    );
            });
        }

        $filtered = (clone $query)->count();
        $orderColumn = $columns[$orderIndex] ?? 'id';
        $rows = $query
            ->orderBy($orderColumn, $orderDirection)
            ->skip($start)
            ->take($length)
            ->get();

        $data = $rows
            ->map(fn (ProductBom $bom): array => $this->tableRow($bom, $request))
            ->all();

        return response()->json([
            'draw' => $draw,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $data,
        ]);
    }

    public function create(): View
    {
        $productBom = new ProductBom([
            'output_quantity' => 1,
            'status' => 'DRAFT',
        ]);

        return $this->formView($productBom, 'Create Finished Product BOM/Recipe', 'POST');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $bom = $this->productBomService->createDraft(
            $data['header'],
            $data['lines'],
            $request->user()?->id,
        );

        return redirect()
            ->route('production.product-boms.show', $bom)
            ->with(
                'success',
                'Finished Product BOM draft created. Review its components, then activate it for assembly.',
            );
    }

    public function show(ProductBom $productBom): View
    {
        $productBom->load([
            'finishedProduct.baseUnit',
            'items.rawMaterial.baseUnit',
            'items.part.baseUnit',
            'items.unit',
            'creator',
            'validityUpdater',
        ]);

        return view('backend.modules.product_bom.show', compact('productBom'));
    }

    public function edit(ProductBom $productBom): View
    {
        abort_unless(
            $productBom->status === 'DRAFT',
            422,
            'Only a Draft BOM can be edited. Create a new version for an active or inactive BOM.',
        );

        $productBom->load([
            'finishedProduct.baseUnit',
            'items.rawMaterial.baseUnit',
            'items.part.baseUnit',
            'items.unit',
        ]);

        return $this->formView($productBom, 'Edit Finished Product BOM/Recipe', 'PUT');
    }

    public function update(Request $request, ProductBom $productBom): RedirectResponse
    {
        $data = $this->validated($request, $productBom);
        $productBom = $this->productBomService->updateDraft(
            $productBom,
            $data['header'],
            $data['lines'],
        );

        return redirect()
            ->route('production.product-boms.show', $productBom)
            ->with('success', 'Finished Product BOM draft updated successfully.');
    }

    public function validityModal(ProductBom $productBom): View
    {
        abort_if(
            $productBom->status === 'DRAFT',
            422,
            'Edit the Draft BOM directly instead of extending its validity.',
        );
        abort_if(
            $productBom->effective_to === null,
            422,
            'This BOM already has no expiry date.',
        );

        $productBom->load('finishedProduct:id,product_code,name');

        return view('backend.modules.product_bom.validity_modal', compact('productBom'));
    }

    public function extendValidity(Request $request, ProductBom $productBom): JsonResponse
    {
        $data = $request->validate([
            'effective_to' => ['nullable', 'date', 'after_or_equal:today'],
        ], [
            'effective_to.after_or_equal' => 'The new validity date cannot be in the past.',
        ]);

        $productBom = $this->productBomService->extendValidity(
            $productBom,
            $data['effective_to'] ?? null,
            $request->user()?->id,
        );
        $validity = $productBom->effective_to?->format('d M Y') ?? 'No expiry';

        return response()->json([
            'success' => true,
            'msg' => "BOM validity extended successfully. Effective To: {$validity}.",
        ]);
    }

    public function activate(ProductBom $productBom): RedirectResponse
    {
        $productBom = $this->productBomService->activate($productBom);

        return redirect()
            ->route('production.product-boms.show', $productBom)
            ->with(
                'success',
                'Finished Product BOM activated. Any previous active version is now inactive.',
            );
    }

    public function deactivate(ProductBom $productBom): RedirectResponse
    {
        $productBom = $this->productBomService->deactivate($productBom);

        return redirect()
            ->route('production.product-boms.show', $productBom)
            ->with(
                'success',
                'Finished Product BOM deactivated and is no longer available for assembly.',
            );
    }

    public function destroy(Request $request, ProductBom $productBom): RedirectResponse|JsonResponse
    {
        $this->productBomService->deleteDraft($productBom);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'msg' => 'Finished Product BOM draft deleted successfully.',
            ]);
        }

        return redirect()
            ->route('production.product-boms.index')
            ->with('success', 'Finished Product BOM draft deleted successfully.');
    }

    public function finishedProductSelect2(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $products = FinishedProduct::query()
            ->where('is_active', true)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('product_code', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'product_code', 'name'])
            ->map(fn (FinishedProduct $product): array => [
                'id' => $product->id,
                'text' => "{$product->name} ({$product->product_code})",
            ]);

        return response()->json(['results' => $products]);
    }

    public function componentSelect2(Request $request): JsonResponse
    {
        $type = strtoupper((string) $request->input('type'));
        $search = trim((string) $request->input('q', ''));

        $results = match ($type) {
            'RAW_MATERIAL' => $this->rawMaterialOptions($search),
            'PART' => $this->partOptions($search),
            default => collect(),
        };

        return response()->json(['results' => $results]);
    }

    public function componentUnits(string $componentType, int $component): JsonResponse
    {
        $type = strtoupper(str_replace('-', '_', $componentType));

        abort_unless(in_array($type, ['RAW_MATERIAL', 'PART'], true), 404);

        $model = $type === 'RAW_MATERIAL'
            ? RawMaterial::query()->whereKey($component)->where('is_active', true)->firstOrFail()
            : Part::query()->whereKey($component)->where('is_active', true)->firstOrFail();

        $baseUnit = Unit::query()
            ->whereKey($model->base_unit_id)
            ->where('is_active', true)
            ->firstOrFail(['id', 'name', 'code', 'precision']);
        $units = $this->componentUnitOptions($type, $model->id, $baseUnit);

        return response()->json([
            'component' => [
                'id' => $model->id,
                'type' => $type,
                'name' => $model->name,
                'code' => $type === 'RAW_MATERIAL'
                    ? $model->material_code
                    : $model->part_code,
                'base_unit' => "{$baseUnit->name} ({$baseUnit->code})",
            ],
            'units' => $units,
        ]);
    }

    private function tableRow(ProductBom $bom, Request $request): array
    {
        $product = '<strong>'.e($bom->finishedProduct?->name ?? '—').'</strong>'
            .'<br><code>'.e($bom->finishedProduct?->product_code ?? '—').'</code>';
        $output = $this->formatQuantity($bom->output_quantity)
            .' '.e($bom->finishedProduct?->baseUnit?->code ?? '');
        $dates = e($bom->effective_from?->format('d M Y') ?? '—')
            .'<span class="text-muted mx-1">to</span>'
            .e($bom->effective_to?->format('d M Y') ?? 'No expiry');

        return [
            $bom->id,
            $product,
            '<code>'.e($bom->version).'</code>',
            '<span class="text-end d-block fw-semibold">'.$output.'</span>',
            '<span class="badge bg-primary-focus text-primary-600 px-10 py-6 radius-6">'
                .e((string) $bom->items_count)
                .'</span>',
            '<span class="text-sm">'.$dates.'</span>',
            $this->statusBadge($bom->status).$this->validityBadge($bom),
            $this->actionButtons($bom, $request),
        ];
    }

    private function actionButtons(ProductBom $bom, Request $request): string
    {
        $user = $request->user();
        $actions = '<div class="d-inline-flex justify-content-end align-items-center gap-1 w-100">';

        if ($user?->canDo('production.product-boms.show', 'show')) {
            $actions .= '<a href="'.e(route('production.product-boms.show', $bom)).'" '
                .'class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center '
                .'justify-content-center bg-info-focus text-info-main" title="View">'
                .'<iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';
        }

        if ($bom->status === 'DRAFT' && $user?->canDo('production.product-boms.edit', 'edit')) {
            $actions .= '<a href="'.e(route('production.product-boms.edit', $bom)).'" '
                .'class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center '
                .'justify-content-center bg-success-focus text-success-main" title="Edit draft">'
                .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
        }

        if (
            $bom->status !== 'DRAFT'
            && $bom->effective_to
            && $user?->canDo('production.product-boms.extend-validity', 'edit')
        ) {
            $actions .= '<button type="button" class="btn w-32-px h-32-px rounded-circle '
                .'d-inline-flex align-items-center justify-content-center bg-warning-focus '
                .'text-warning-main AjaxModal" data-ajax-modal="'
                .e(route('production.product-boms.validityModal', $bom)).'" data-size="md" '
                .'data-onsuccess="ProductBomIndex.onValiditySaved" title="Extend validity">'
                .'<iconify-icon icon="mdi:calendar-refresh-outline"></iconify-icon></button>';
        }

        if (
            $bom->status === 'DRAFT'
            && $user?->canDo('production.product-boms.destroy', 'delete')
        ) {
            $actions .= '<button type="button" class="btn w-32-px h-32-px rounded-circle '
                .'d-inline-flex align-items-center justify-content-center bg-danger-focus '
                .'text-danger-main btn-product-bom-delete" data-url="'
                .e(route('production.product-boms.destroy', $bom)).'" title="Delete draft">'
                .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></button>';
        }

        return $actions.'</div>';
    }

    private function rawMaterialOptions(string $search): Collection
    {
        return RawMaterial::query()
            ->where('is_active', true)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('material_code', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'material_code', 'name'])
            ->map(fn (RawMaterial $material): array => [
                'id' => $material->id,
                'text' => "{$material->name} ({$material->material_code})",
            ]);
    }

    private function partOptions(string $search): Collection
    {
        return Part::query()
            ->where('is_active', true)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('part_code', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'part_code', 'name', 'procurement_mode'])
            ->map(fn (Part $part): array => [
                'id' => $part->id,
                'text' => "{$part->name} ({$part->part_code}) — {$part->procurement_mode}",
            ]);
    }

    private function componentUnitOptions(
        string $type,
        int $componentId,
        Unit $baseUnit,
    ): Collection {
        $mappingTable = $type === 'RAW_MATERIAL' ? 'raw_material_units' : 'part_units';
        $foreignKey = $type === 'RAW_MATERIAL' ? 'raw_material_id' : 'part_id';
        $alternativeUnits = DB::table("{$mappingTable} as mapping")
            ->join('units as unit', 'unit.id', '=', 'mapping.unit_id')
            ->where("mapping.{$foreignKey}", $componentId)
            ->whereIn('mapping.usage_type', ['PRODUCTION', 'ALL'])
            ->where('unit.is_active', true)
            ->whereNull('unit.deleted_at')
            ->orderBy('unit.name')
            ->get([
                'unit.id',
                'unit.name',
                'unit.code',
                'unit.precision',
                'mapping.conversion_to_base',
            ]);

        $units = collect([[
            'id' => $baseUnit->id,
            'text' => "{$baseUnit->name} ({$baseUnit->code}) — Base unit",
            'factor' => '1',
            'precision' => $baseUnit->precision,
            'is_base' => true,
        ]]);

        foreach ($alternativeUnits as $unit) {
            $units->push([
                'id' => $unit->id,
                'text' => "{$unit->name} ({$unit->code})",
                'factor' => (string) $unit->conversion_to_base,
                'precision' => $unit->precision,
                'is_base' => false,
            ]);
        }

        return $units;
    }

    private function formView(ProductBom $productBom, string $title, string $method): View
    {
        return view('backend.modules.product_bom.form', [
            'productBom' => $productBom,
            'existingLines' => $this->existingLines($productBom),
            'title' => $title,
            'method' => $method,
            'action' => $method === 'POST'
                ? route('production.product-boms.store')
                : route('production.product-boms.update', $productBom),
        ]);
    }

    private function existingLines(ProductBom $productBom): array
    {
        $submittedLines = request()->old('lines');

        if (is_array($submittedLines)) {
            return collect($submittedLines)
                ->map(function (array $line): array {
                    $type = $line['component_type'] ?? 'RAW_MATERIAL';
                    $componentId = (int) ($line['component_id'] ?? 0);

                    return [
                        'component_type' => $type,
                        'component_id' => $componentId ?: null,
                        'component_text' => $this->componentText($type, $componentId),
                        'unit_id' => $line['unit_id'] ?? null,
                        'required_quantity' => $line['required_quantity'] ?? null,
                        'wastage_percent' => $line['wastage_percent'] ?? 0,
                    ];
                })
                ->values()
                ->all();
        }

        if (! $productBom->relationLoaded('items')) {
            return [];
        }

        return $productBom->items
            ->map(function ($item): array {
                $type = $item->raw_material_id !== null ? 'RAW_MATERIAL' : 'PART';
                $componentId = (int) ($item->raw_material_id ?? $item->part_id);

                return [
                    'component_type' => $type,
                    'component_id' => $componentId,
                    'component_text' => $this->componentText($type, $componentId),
                    'unit_id' => $item->unit_id,
                    'required_quantity' => $item->required_quantity,
                    'wastage_percent' => $item->wastage_percent,
                ];
            })
            ->values()
            ->all();
    }

    private function componentText(string $type, int $componentId): string
    {
        if ($componentId <= 0) {
            return '';
        }

        if ($type === 'PART') {
            $part = Part::withTrashed()->find($componentId);

            return $part ? "{$part->name} ({$part->part_code})" : '';
        }

        $material = RawMaterial::withTrashed()->find($componentId);

        return $material ? "{$material->name} ({$material->material_code})" : '';
    }

    private function validated(Request $request, ?ProductBom $productBom = null): array
    {
        $request->merge([
            'version' => strtoupper(trim((string) $request->input('version'))),
        ]);

        $data = $request->validate([
            'finished_product_id' => ['required', 'integer'],
            'version' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('product_boms', 'version')
                    ->where(fn (QueryBuilder $query) => $query->where(
                        'finished_product_id',
                        $request->integer('finished_product_id'),
                    ))
                    ->ignore($productBom?->id),
            ],
            'output_quantity' => [
                'required',
                'numeric',
                'gt:0',
                'max:99999999999999.999999',
            ],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.component_type' => ['required', Rule::in(['RAW_MATERIAL', 'PART'])],
            'lines.*.component_id' => ['required', 'integer'],
            'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.required_quantity' => [
                'required',
                'numeric',
                'gt:0',
                'max:99999999999999.999999',
            ],
            'lines.*.wastage_percent' => ['nullable', 'numeric', 'between:0,100'],
        ], [
            'version.regex' => 'Version may contain only uppercase letters, numbers, dots, underscores and hyphens.',
        ]);

        return [
            'header' => [
                'finished_product_id' => (int) $data['finished_product_id'],
                'version' => $data['version'],
                'output_quantity' => $data['output_quantity'],
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
            ],
            'lines' => collect($data['lines'])
                ->map(fn (array $line): array => [
                    'component_type' => $line['component_type'],
                    'component_id' => (int) $line['component_id'],
                    'unit_id' => (int) $line['unit_id'],
                    'required_quantity' => $line['required_quantity'],
                    'wastage_percent' => $line['wastage_percent'] ?? 0,
                ])
                ->values()
                ->all(),
        ];
    }

    private function statusBadge(string $status): string
    {
        $class = match ($status) {
            'ACTIVE' => 'bg-dark-success-gradient',
            'INACTIVE' => 'bg-dark-secondary-gradient',
            default => 'bg-dark-warning-gradient',
        };

        return '<span class="badge text-sm fw-semibold '.$class
            .' px-12 py-7 radius-4 text-white">'
            .e(ucfirst(strtolower($status)))
            .'</span>';
    }

    private function validityBadge(ProductBom $bom): string
    {
        if ($bom->isExpired()) {
            return '<span class="badge text-sm fw-semibold bg-dark-danger-gradient text-white '
                .'px-12 py-7 radius-4 ms-1">Expired</span>';
        }

        if ($bom->isScheduled()) {
            return '<span class="badge text-sm fw-semibold bg-dark-info-gradient text-white '
                .'px-12 py-7 radius-4 ms-1">Scheduled</span>';
        }

        return '';
    }

    private function formatQuantity(string|float|int $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 6, '.', ''), '0'), '.');
    }
}
