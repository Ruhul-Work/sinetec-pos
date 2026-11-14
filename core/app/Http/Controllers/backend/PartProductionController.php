<?php

namespace App\Http\Controllers\backend;

use App\Enums\Production\PartProductionStatus;
use App\Enums\Production\ProductionQcStatus;
use App\Exceptions\Inventory\StockPostingException;
use App\Exceptions\Production\InvalidPartProductionException;
use App\Http\Controllers\Controller;
use App\Models\Production\PartBom;
use App\Models\Production\PartProductionBatch;
use App\Models\Production\ProductionExpenseCategory;
use App\Services\Production\PartProductionService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PartProductionController extends Controller
{
    public function __construct(private readonly PartProductionService $productionService) {}

    public function index(): View
    {
        return view('backend.modules.part_production.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = [
            'batch.id',
            'batch.batch_no',
            'batch.production_date',
            'part.name',
            'input_warehouse.name',
            'batch.planned_quantity',
            'batch.good_quantity',
            'batch.qc_status',
            'batch.id',
            'batch.status',
        ];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));
        $status = strtoupper(trim((string) $request->input('status', '')));

        $query = DB::table('part_production_batches as batch')
            ->join('part_boms as bom', 'bom.id', '=', 'batch.part_bom_id')
            ->join('parts as part', 'part.id', '=', 'bom.part_id')
            ->join('warehouses as input_warehouse', 'input_warehouse.id', '=', 'batch.input_warehouse_id')
            ->join('warehouses as output_warehouse', 'output_warehouse.id', '=', 'batch.output_warehouse_id')
            ->join('branches as branch', 'branch.id', '=', 'batch.branch_id')
            ->leftJoinSub(
                $this->materialCostTotalsQuery(),
                'material_cost',
                'material_cost.part_production_batch_id',
                '=',
                'batch.id',
            )
            ->leftJoinSub(
                $this->directCostTotalsQuery(),
                'direct_cost',
                'direct_cost.part_production_batch_id',
                '=',
                'batch.id',
            )
            ->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('batch.branch_id', $branchId))
            ->select([
                'batch.id',
                'batch.batch_no',
                'batch.production_date',
                'batch.planned_quantity',
                'batch.good_quantity',
                'batch.reject_quantity',
                'batch.qc_status',
                'batch.status',
                'bom.version as bom_version',
                'part.name as part_name',
                'part.part_code',
                'branch.name as branch_name',
                'input_warehouse.name as input_warehouse_name',
                'input_warehouse.code as input_warehouse_code',
                'output_warehouse.name as output_warehouse_name',
                'output_warehouse.code as output_warehouse_code',
                DB::raw('COALESCE(material_cost.total_cost, 0) as material_cost'),
                DB::raw('COALESCE(direct_cost.total_cost, 0) as direct_cost'),
            ]);

        $total = (clone $query)->count();

        if (in_array($status, array_column(PartProductionStatus::cases(), 'value'), true)) {
            $query->where('batch.status', $status);
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('batch.batch_no', 'like', "%{$search}%")
                    ->orWhere('part.name', 'like', "%{$search}%")
                    ->orWhere('part.part_code', 'like', "%{$search}%")
                    ->orWhere('branch.name', 'like', "%{$search}%")
                    ->orWhere('input_warehouse.name', 'like', "%{$search}%")
                    ->orWhere('output_warehouse.name', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $rows = $query
            ->orderBy($columns[$orderIndex] ?? 'batch.id', $orderDirection)
            ->skip($start)
            ->take($length)
            ->get();

        $user = $request->user();
        $canShow = $user?->canDo('production.part-productions.show', 'show') ?? false;
        $canEdit = $user?->canDo('production.part-productions.edit', 'edit') ?? false;
        $canDelete = $user?->canDo('production.part-productions.destroy', 'delete') ?? false;

        $data = $rows->map(function (object $row) use ($canShow, $canEdit, $canDelete): array {
            $status = PartProductionStatus::from($row->status);
            $qcStatus = ProductionQcStatus::from($row->qc_status);
            $actions = '<div class="d-inline-flex justify-content-end align-items-center gap-1 w-100">';

            if ($canShow) {
                $actions .= '<a href="'.e(route('production.part-productions.show', $row->id)).'" '
                    .'class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center '
                    .'justify-content-center bg-info-focus text-info-main" title="View">'
                    .'<iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';
            }

            if ($status === PartProductionStatus::Draft && $canEdit) {
                $actions .= '<a href="'.e(route('production.part-productions.edit', $row->id)).'" '
                    .'class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center '
                    .'justify-content-center bg-success-focus text-success-main" title="Edit draft">'
                    .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }

            if ($status === PartProductionStatus::Draft && $canDelete) {
                $actions .= '<button type="button" '
                    .'class="btn w-32-px h-32-px rounded-circle d-inline-flex align-items-center '
                    .'justify-content-center bg-danger-focus text-danger-main btn-part-production-delete" '
                    .'data-url="'.e(route('production.part-productions.destroy', $row->id)).'" '
                    .'title="Delete draft">'
                    .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></button>';
            }

            $actions .= '</div>';

            return [
                $row->id,
                '<a class="fw-semibold text-primary-600" '
                    .'href="'.e(route('production.part-productions.show', $row->id)).'">'
                    .e($row->batch_no).'</a>',
                e(date('d M Y', strtotime($row->production_date))),
                '<strong>'.e($row->part_name).'</strong><br><code>'.e($row->part_code).'</code> '
                    .'<span class="text-muted text-xs">'.e($row->bom_version).'</span>',
                '<span class="text-sm"><strong>'.e($row->input_warehouse_name).'</strong> → '
                    .e($row->output_warehouse_name).'</span><br>'
                    .'<span class="text-muted text-xs">'.e($row->branch_name).'</span>',
                '<span class="text-end d-block">'.$this->formatQuantity($row->planned_quantity).'</span>',
                '<span class="text-end d-block fw-semibold">'.$this->formatQuantity($row->good_quantity).'</span>',
                $this->qcBadge($qcStatus),
                $this->productionCostHtml($row),
                $this->statusBadge($status),
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
        $batch = new PartProductionBatch([
            'production_date' => now()->toDateString(),
            'planned_quantity' => 1,
            'good_quantity' => 0,
            'reject_quantity' => 0,
            'qc_status' => ProductionQcStatus::Pending,
            'status' => PartProductionStatus::Draft,
        ]);

        return $this->formView($batch, 'Create Part Production Batch', 'POST');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $batch = $this->productionService->createDraft(
                $data['header'],
                $data['consumptions'],
                $data['expenses'],
                $request->user()?->id,
            );
        } catch (InvalidPartProductionException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('production.part-productions.show', $batch)
            ->with('success', 'Part production draft created. Review stock availability and QC before completion.');
    }

    public function show(int $partProduction): View
    {
        $batch = $this->record($partProduction);

        return view('backend.modules.part_production.show', [
            'batch' => $batch,
            'materialCostPreview' => $this->productionService->materialCostPreview($batch),
        ]);
    }

    public function edit(int $partProduction): View
    {
        $batch = $this->record($partProduction);
        abort_unless($batch->status === PartProductionStatus::Draft, 409, 'Only a Draft production batch can be edited.');

        return $this->formView($batch, 'Edit Part Production Batch', 'PUT');
    }

    public function update(Request $request, int $partProduction): RedirectResponse
    {
        $batch = $this->record($partProduction);
        $data = $this->validated($request);

        try {
            $batch = $this->productionService->updateDraft(
                $batch,
                $data['header'],
                $data['consumptions'],
                $data['expenses'],
            );
        } catch (InvalidPartProductionException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('production.part-productions.show', $batch)
            ->with('success', 'Part production draft updated successfully.');
    }

    public function complete(Request $request, int $partProduction): RedirectResponse
    {
        try {
            $batch = $this->productionService->complete(
                $this->record($partProduction),
                $request->user()?->id,
            );
        } catch (InvalidPartProductionException|StockPostingException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            'Production completed. Raw Material stock was consumed and good Part stock was received. '
            .'Recorded costs remain for reporting only; no Finance journal was created.',
        );
    }

    public function destroy(int $partProduction): JsonResponse
    {
        try {
            $this->productionService->deleteDraft($this->record($partProduction));
        } catch (InvalidPartProductionException $exception) {
            return response()->json(['success' => false, 'msg' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'msg' => 'Part production draft deleted successfully.']);
    }

    public function bomPlan(Request $request, PartBom $partBom): JsonResponse
    {
        abort_unless($partBom->status === 'ACTIVE', 404);
        $request->validate([
            'planned_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.999999'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        $warehouseId = $request->integer('warehouse_id') ?: null;
        if ($warehouseId) {
            $warehouseAllowed = DB::table('warehouses')
                ->where('id', $warehouseId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->when(current_branch_id(), fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
                ->exists();
            abort_unless($warehouseAllowed, 404);
        }

        $partBom->loadMissing('part.baseUnit');

        return response()->json([
            'bom' => [
                'id' => $partBom->id,
                'part_name' => $partBom->part?->name,
                'part_code' => $partBom->part?->part_code,
                'version' => $partBom->version,
                'output_unit' => $partBom->part?->baseUnit?->code,
            ],
            'consumptions' => $this->productionService->plannedConsumptions(
                $partBom,
                $request->input('planned_quantity'),
                $warehouseId,
            ),
        ]);
    }

    private function formView(PartProductionBatch $batch, string $title, string $method): View
    {
        $warehouses = DB::table('warehouses as warehouse')
            ->join('branches as branch', 'branch.id', '=', 'warehouse.branch_id')
            ->whereNull('warehouse.deleted_at')
            ->whereNull('branch.deleted_at')
            ->where('warehouse.is_active', true)
            ->where('branch.is_active', true)
            ->when(current_branch_id(), fn (Builder $query, int $branchId) => $query->where('warehouse.branch_id', $branchId))
            ->orderBy('branch.name')
            ->orderByDesc('warehouse.is_default')
            ->orderBy('warehouse.name')
            ->get([
                'warehouse.id',
                'warehouse.branch_id',
                'warehouse.name',
                'warehouse.code',
                'branch.name as branch_name',
            ]);

        $boms = PartBom::query()
            ->with('part.baseUnit')
            ->where(function (EloquentBuilder $query) use ($batch): void {
                $query->where('status', 'ACTIVE');
                if ($batch->exists) {
                    $query->orWhereKey($batch->part_bom_id);
                }
            })
            ->orderByDesc('status')
            ->orderByDesc('id')
            ->get();

        $expenseCategories = ProductionExpenseCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return view('backend.modules.part_production.form', [
            'batch' => $batch,
            'title' => $title,
            'method' => $method,
            'action' => $method === 'POST'
                ? route('production.part-productions.store')
                : route('production.part-productions.update', $batch),
            'warehouses' => $warehouses,
            'boms' => $boms,
            'expenseCategories' => $expenseCategories,
            'qcStatuses' => ProductionQcStatus::cases(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'part_bom_id' => ['required', 'integer', 'exists:part_boms,id'],
            'input_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(function ($query): void {
                $query->whereNull('deleted_at')->where('is_active', true);
                if ($branchId = current_branch_id()) {
                    $query->where('branch_id', $branchId);
                }
            })],
            'output_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(function ($query): void {
                $query->whereNull('deleted_at')->where('is_active', true);
                if ($branchId = current_branch_id()) {
                    $query->where('branch_id', $branchId);
                }
            })],
            'production_date' => ['required', 'date'],
            'planned_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.999999'],
            'good_quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999.999999'],
            'reject_quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999.999999'],
            'qc_status' => ['required', Rule::enum(ProductionQcStatus::class)],
            'qc_note' => ['nullable', 'string', 'max:5000'],
            'consumptions' => ['required', 'array', 'min:1'],
            'consumptions.*.raw_material_id' => ['required', 'integer', 'distinct'],
            'consumptions.*.unit_id' => ['required', 'integer'],
            'consumptions.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.999999'],
            'expenses' => ['nullable', 'array'],
            'expenses.*.production_expense_category_id' => ['nullable', 'integer'],
            'expenses.*.amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999999.9999'],
            'expenses.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        return [
            'header' => [
                'part_bom_id' => (int) $data['part_bom_id'],
                'input_warehouse_id' => (int) $data['input_warehouse_id'],
                'output_warehouse_id' => (int) $data['output_warehouse_id'],
                'production_date' => $data['production_date'],
                'planned_quantity' => $data['planned_quantity'],
                'good_quantity' => $data['good_quantity'],
                'reject_quantity' => $data['reject_quantity'],
                'qc_status' => $data['qc_status'],
                'qc_note' => $data['qc_note'] ?? null,
            ],
            'consumptions' => $data['consumptions'],
            'expenses' => array_values(array_filter(
                $data['expenses'] ?? [],
                fn (array $expense): bool => filled($expense['production_expense_category_id'] ?? null)
                    || filled($expense['amount'] ?? null)
                    || filled($expense['note'] ?? null),
            )),
        ];
    }

    private function record(int $id): PartProductionBatch
    {
        $batch = PartProductionBatch::query()
            ->whereKey($id)
            ->when(current_branch_id(), fn (EloquentBuilder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->with([
                'bom.part.baseUnit',
                'branch',
                'inputWarehouse',
                'outputWarehouse',
                'consumptions.rawMaterial.baseUnit',
                'consumptions.unit',
                'expenses.category',
                'creator',
                'completedBy',
            ])
            ->first();

        abort_unless($batch, 404);

        return $batch;
    }

    private function materialCostTotalsQuery(): Builder
    {
        return DB::table('part_production_consumptions')
            ->groupBy('part_production_batch_id')
            ->selectRaw(
                'part_production_batch_id, SUM(total_cost) as total_cost',
            );
    }

    private function directCostTotalsQuery(): Builder
    {
        return DB::table('part_production_expenses')
            ->groupBy('part_production_batch_id')
            ->selectRaw(
                'part_production_batch_id, SUM(amount) as total_cost',
            );
    }

    private function productionCostHtml(object $row): string
    {
        $materialCost = max((float) $row->material_cost, 0);
        $directCost = max((float) $row->direct_cost, 0);

        if ($row->status === PartProductionStatus::Draft->value) {
            return '<div class="d-flex flex-column align-items-end">'
                .'<strong class="text-muted">Pending completion</strong>'
                .'<small class="text-muted">Other estimate '.number_format($directCost, 2).'</small>'
                .$this->reportOnlyBadge()
                .'</div>';
        }

        $totalCost = $materialCost + $directCost;

        return '<div class="d-flex flex-column align-items-end">'
            .'<strong>'.number_format($totalCost, 2).'</strong>'
            .'<small class="text-muted">Material '.number_format($materialCost, 2)
            .' + Other '.number_format($directCost, 2).'</small>'
            .$this->reportOnlyBadge()
            .'</div>';
    }

    private function reportOnlyBadge(): string
    {
        return '<span class="badge text-xxs fw-semibold bg-info-focus text-info-main '
            .'px-6 py-3 radius-4 mt-1 lh-1">Report only</span>';
    }

    private function statusBadge(PartProductionStatus $status): string
    {
        $class = match ($status) {
            PartProductionStatus::Completed => 'bg-dark-success-gradient',
            PartProductionStatus::InProgress => 'bg-dark-info-gradient',
            PartProductionStatus::Void => 'bg-dark-danger-gradient',
            PartProductionStatus::Draft => 'bg-dark-warning-gradient',
        };

        return '<span class="badge text-sm fw-semibold '.$class.' px-12 py-7 radius-4 text-white">'
            .e($status->label()).'</span>';
    }

    private function qcBadge(ProductionQcStatus $status): string
    {
        $class = match ($status) {
            ProductionQcStatus::Passed => 'bg-success-focus text-success-main',
            ProductionQcStatus::Partial => 'bg-warning-focus text-warning-main',
            ProductionQcStatus::Failed => 'bg-danger-focus text-danger-main',
            ProductionQcStatus::Pending => 'bg-secondary-focus text-secondary-main',
        };

        return '<span class="badge '.$class.' px-10 py-6 radius-6">'.e($status->label()).'</span>';
    }

    private function formatQuantity(mixed $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 6, '.', ''), '0'), '.');
    }
}
