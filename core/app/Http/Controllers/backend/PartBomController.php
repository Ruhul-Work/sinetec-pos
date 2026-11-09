<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\Part;
use App\Models\backend\RawMaterial;
use App\Models\backend\Unit;
use App\Models\Production\PartBom;
use App\Services\Production\PartBomService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PartBomController extends Controller
{
    public function __construct(private readonly PartBomService $partBomService) {}

    public function index(): View
    {
        return view('backend.modules.part_bom.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['id', 'part_id', 'version', 'output_quantity', 'items_count', 'effective_from', 'status'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $status = strtoupper(trim((string) $request->input('status', '')));
        $search = trim((string) $request->input('search.value', ''));

        $query = PartBom::query()
            ->with(['part:id,part_code,name,base_unit_id', 'part.baseUnit:id,name,code'])
            ->withCount('items');

        $total = (clone $query)->count();

        if (in_array($status, ['DRAFT', 'ACTIVE', 'INACTIVE'], true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('version', 'like', "%{$search}%")
                        ->orWhereHas('part', fn (Builder $part) => $part
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('part_code', 'like', "%{$search}%"));
                });
            });
        }

        $filtered = (clone $query)->count();
        $orderColumn = $columns[$orderIndex] ?? 'id';
        $rows = $query
            ->orderBy($orderColumn, $orderDirection)
            ->skip($start)
            ->take($length)
            ->get();

        $user = $request->user();
        $canShow = $user?->canDo('production.part-boms.show', 'show') ?? false;
        $canEdit = $user?->canDo('production.part-boms.edit', 'edit') ?? false;
        $canDelete = $user?->canDo('production.part-boms.destroy', 'delete') ?? false;

        $data = $rows->map(function (PartBom $bom) use ($canShow, $canEdit, $canDelete): array {
            $part = '<strong>'.e($bom->part?->name ?? '—').'</strong><br><code>'.e($bom->part?->part_code ?? '—').'</code>';
            $output = $this->formatQuantity($bom->output_quantity).' '.e($bom->part?->baseUnit?->code ?? '');
            $dates = e($bom->effective_from?->format('d M Y') ?? '—')
            .'<span class="text-muted mx-1">to</span>'
            .e($bom->effective_to?->format('d M Y') ?? '—');
            $actions = '<div class="d-inline-flex justify-content-end align-items-center gap-1 w-100">';

            if ($canShow) {
                $actions .= '<a href="'.e(route('production.part-boms.show', $bom)).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main" title="View">'
                    .'<iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';
            }

            if ($bom->status === 'DRAFT' && $canEdit) {
                $actions .= '<a href="'.e(route('production.part-boms.edit', $bom)).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main" title="Edit draft">'
                    .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }

            if ($bom->status === 'DRAFT' && $canDelete) {
                $actions .= '<button type="button" class="btn w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-part-bom-delete" data-url="'.e(route('production.part-boms.destroy', $bom)).'" title="Delete draft">'
                    .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></button>';
            }

            $actions .= '</div>';

            return [
                $bom->id,
                $part,
                '<code>'.e($bom->version).'</code>',
                '<span class="text-end d-block fw-semibold">'.$output.'</span>',
                '<span class="badge bg-primary-focus text-primary-600 px-10 py-6 radius-6">'.e((string) $bom->items_count).'</span>',
                '<span class="text-sm">'.$dates.'</span>',
                $this->statusBadge($bom->status),
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

    public function create(): View
    {
        return $this->formView(new PartBom(['output_quantity' => 1, 'status' => 'DRAFT']), 'Create Part BOM', 'POST');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $bom = $this->partBomService->createDraft($data['header'], $data['lines'], $request->user()?->id);

        return redirect()
            ->route('production.part-boms.show', $bom)
            ->with('success', 'Part BOM draft created successfully. Review the lines, then activate it when ready for production.');
    }

    public function show(PartBom $partBom): View
    {
        $partBom->load(['part.baseUnit', 'items.rawMaterial.baseUnit', 'items.unit', 'creator']);

        return view('backend.modules.part_bom.show', compact('partBom'));
    }

    public function edit(PartBom $partBom): View
    {
        abort_unless($partBom->status === 'DRAFT', 422, 'Only a Draft BOM can be edited. Create a new version for an active or inactive BOM.');
        $partBom->load(['part.baseUnit', 'items.rawMaterial.baseUnit', 'items.unit']);

        return $this->formView($partBom, 'Edit Part BOM', 'PUT');
    }

    public function update(Request $request, PartBom $partBom): RedirectResponse
    {
        $data = $this->validated($request, $partBom);
        $partBom = $this->partBomService->updateDraft($partBom, $data['header'], $data['lines']);

        return redirect()
            ->route('production.part-boms.show', $partBom)
            ->with('success', 'Part BOM draft updated successfully.');
    }

    public function activate(PartBom $partBom): RedirectResponse
    {
        $partBom = $this->partBomService->activate($partBom);

        return redirect()
            ->route('production.part-boms.show', $partBom)
            ->with('success', 'Part BOM activated. Any previous active version of this Part is now inactive.');
    }

    public function deactivate(PartBom $partBom): RedirectResponse
    {
        $partBom = $this->partBomService->deactivate($partBom);

        return redirect()
            ->route('production.part-boms.show', $partBom)
            ->with('success', 'Part BOM deactivated. It can no longer be selected by the future Production Batch flow.');
    }

    public function destroy(Request $request, PartBom $partBom): RedirectResponse|JsonResponse
    {
        $this->partBomService->deleteDraft($partBom);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'msg' => 'Part BOM draft deleted successfully.',
            ]);
        }

        return redirect()
            ->route('production.part-boms.index')
            ->with('success', 'Part BOM draft deleted successfully.');
    }

    public function partSelect2(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));

        $parts = Part::query()
            ->where('is_active', true)
            ->whereIn('procurement_mode', ['MAKE', 'BOTH'])
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
                'text' => "{$part->name} ({$part->part_code}) — ".ucfirst(strtolower($part->procurement_mode)),
            ]);

        return response()->json(['results' => $parts]);
    }

    public function rawMaterialUnits(RawMaterial $rawMaterial): JsonResponse
    {
        abort_unless($rawMaterial->is_active, 404);

        $baseUnit = Unit::query()
            ->whereKey($rawMaterial->base_unit_id)
            ->where('is_active', true)
            ->firstOrFail(['id', 'name', 'code', 'precision']);

        $alternativeUnits = DB::table('raw_material_units as mapping')
            ->join('units as unit', 'unit.id', '=', 'mapping.unit_id')
            ->where('mapping.raw_material_id', $rawMaterial->id)
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

        return response()->json([
            'material' => [
                'id' => $rawMaterial->id,
                'name' => $rawMaterial->name,
                'code' => $rawMaterial->material_code,
                'base_unit' => "{$baseUnit->name} ({$baseUnit->code})",
            ],
            'units' => $units,
        ]);
    }

    private function formView(PartBom $partBom, string $title, string $method): View
    {
        return view('backend.modules.part_bom.form', [
            'partBom' => $partBom,
            'title' => $title,
            'method' => $method,
            'action' => $method === 'POST'
                ? route('production.part-boms.store')
                : route('production.part-boms.update', $partBom),
        ]);
    }

    private function validated(Request $request, ?PartBom $partBom = null): array
    {
        $request->merge([
            'version' => strtoupper(trim((string) $request->input('version'))),
        ]);

        $data = $request->validate([
            'part_id' => ['required', 'integer'],
            'version' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('part_boms', 'version')
                    // Laravel's presence verifier passes a base query builder here,
                    // not an Eloquent model query builder.
                    ->where(fn (QueryBuilder $query) => $query->where('part_id', $request->integer('part_id')))
                    ->ignore($partBom?->id),
            ],
            'output_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.999999'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.raw_material_id' => ['required', 'integer', 'distinct'],
            'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.required_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.999999'],
            'lines.*.wastage_percent' => ['nullable', 'numeric', 'between:0,100'],
        ], [
            'version.regex' => 'Version may contain only uppercase letters, numbers, dots, underscores and hyphens.',
            'lines.*.raw_material_id.distinct' => 'A raw material can appear only once in this BOM.',
        ]);

        return [
            'header' => [
                'part_id' => (int) $data['part_id'],
                'version' => $data['version'],
                'output_quantity' => $data['output_quantity'],
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
            ],
            'lines' => collect($data['lines'])
                ->map(fn (array $line): array => [
                    'raw_material_id' => (int) $line['raw_material_id'],
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

        return '<span class="badge text-sm fw-semibold '.$class.' px-12 py-7 radius-4 text-white">'
        .e(ucfirst(strtolower($status))).'</span>';
    }

    private function formatQuantity(string|float|int $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 6, '.', ''), '0'), '.');
    }
}
