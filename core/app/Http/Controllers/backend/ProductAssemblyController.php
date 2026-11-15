<?php

namespace App\Http\Controllers\backend;

use App\Enums\Production\ProductAssemblyStatus;
use App\Enums\Production\ProductionQcStatus;
use App\Exceptions\Inventory\StockPostingException;
use App\Exceptions\Production\InvalidProductAssemblyException;
use App\Http\Controllers\Controller;
use App\Models\Production\ProductAssemblyBatch;
use App\Models\Production\ProductBom;
use App\Models\Production\ProductionExpenseCategory;
use App\Services\Production\ProductAssemblyService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductAssemblyController extends Controller
{
    public function __construct(private readonly ProductAssemblyService $assemblyService) {}

    public function index(): View
    {
        return view('backend.modules.product_assembly.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $status = strtoupper(trim((string) $request->input('status', '')));
        $search = trim((string) $request->input('search.value', ''));
        $query = DB::table('product_assembly_batches as batch')
            ->join('product_boms as bom', 'bom.id', '=', 'batch.product_bom_id')
            ->join('finished_products as product', 'product.id', '=', 'bom.finished_product_id')
            ->join('warehouses as input_warehouse', 'input_warehouse.id', '=', 'batch.input_warehouse_id')
            ->join('warehouses as output_warehouse', 'output_warehouse.id', '=', 'batch.output_warehouse_id')
            ->join('branches as branch', 'branch.id', '=', 'batch.branch_id')
            ->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('batch.branch_id', $branchId))
            ->select([
                'batch.id',
                'batch.batch_no',
                'batch.assembly_date',
                'batch.planned_quantity',
                'batch.good_quantity',
                'batch.qc_status',
                'batch.status',
                'bom.version as bom_version',
                'product.name as product_name',
                'product.product_code',
                'branch.name as branch_name',
                'input_warehouse.name as input_warehouse_name',
                'output_warehouse.name as output_warehouse_name',
            ]);

        $total = (clone $query)->count();
        if (in_array($status, array_column(ProductAssemblyStatus::cases(), 'value'), true)) {
            $query->where('batch.status', $status);
        }
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('batch.batch_no', 'like', "%{$search}%")
                    ->orWhere('product.name', 'like', "%{$search}%")
                    ->orWhere('product.product_code', 'like', "%{$search}%")
                    ->orWhere('branch.name', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $requestedLength = (int) $request->input('length', 10);
        $length = $requestedLength === -1
            ? 1000
            : min(max($requestedLength, 1), 1000);
        $rows = $query
            ->orderByDesc('batch.id')
            ->skip(max((int) $request->input('start', 0), 0))
            ->take($length)
            ->get();
        $user = $request->user();

        $data = $rows->map(function (object $row) use ($user): array {
            $status = ProductAssemblyStatus::from($row->status);
            $actions = '<div class="d-inline-flex justify-content-end align-items-center gap-1 w-100">';
            if ($user?->canDo('production.product-assemblies.show', 'show')) {
                $actions .= '<a href="'.e(route('production.product-assemblies.show', $row->id)).'" '
                    .'class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main" title="View">'
                    .'<iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';
            }
            if ($status === ProductAssemblyStatus::Draft && $user?->canDo('production.product-assemblies.edit', 'edit')) {
                $actions .= '<a href="'.e(route('production.product-assemblies.edit', $row->id)).'" '
                    .'class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center '
                    .'justify-content-center bg-success-focus text-success-main" title="Edit">'
                    .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }
            if ($status === ProductAssemblyStatus::Draft && $user?->canDo('production.product-assemblies.destroy', 'delete')) {
                $actions .= '<button type="button" data-url="'.e(route('production.product-assemblies.destroy', $row->id)).'" '
                    .'class="btn w-32-px h-32-px rounded-circle d-inline-flex align-items-center '
                    .'justify-content-center bg-danger-focus text-danger-main btn-assembly-delete" '
                    .'title="Delete">'
                    .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></button>';
            }

            return [
                $row->id,
                '<a class="fw-semibold text-primary-600" href="'.e(route('production.product-assemblies.show', $row->id)).'">'.e($row->batch_no).'</a>',
                e(date('d M Y', strtotime($row->assembly_date))),
                '<strong>'.e($row->product_name).'</strong><br><code>'.e($row->product_code).'</code> <small>'.e($row->bom_version).'</small>',
                e($row->input_warehouse_name).' &rarr; '.e($row->output_warehouse_name).'<br><small>'.e($row->branch_name).'</small>',
                '<span class="d-block text-end">'.$this->quantity($row->planned_quantity).'</span>',
                '<span class="d-block text-end fw-semibold">'.$this->quantity($row->good_quantity).'</span>',
                $this->qcBadge(ProductionQcStatus::from($row->qc_status)),
                $this->statusBadge($status),
                $actions.'</div>',
            ];
        })->all();

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $data,
        ]);
    }

    public function create(): View
    {
        return $this->formView(new ProductAssemblyBatch([
            'assembly_date' => now()->toDateString(),
            'planned_quantity' => 1,
            'good_quantity' => 0,
            'reject_quantity' => 0,
            'qc_status' => ProductionQcStatus::Pending,
            'status' => ProductAssemblyStatus::Draft,
        ]), 'Create Final Product Assembly', 'POST');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        try {
            $batch = $this->assemblyService->createDraft(
                $data['header'],
                $data['consumptions'],
                $data['expenses'],
                $request->user()?->id,
            );
        } catch (InvalidProductAssemblyException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('production.product-assemblies.show', $batch)
            ->with('success', 'Assembly Draft created. No stock was changed.');
    }

    public function show(int $productAssembly): View
    {
        $batch = $this->record($productAssembly);

        return view('backend.modules.product_assembly.show', [
            'batch' => $batch,
            'costPreview' => $this->assemblyService->costPreview($batch),
        ]);
    }

    public function edit(int $productAssembly): View
    {
        $batch = $this->record($productAssembly);
        abort_unless($batch->status === ProductAssemblyStatus::Draft, 409);

        return $this->formView($batch, 'Edit Final Product Assembly', 'PUT');
    }

    public function update(Request $request, int $productAssembly): RedirectResponse
    {
        $data = $this->validated($request);
        try {
            $batch = $this->assemblyService->updateDraft(
                $this->record($productAssembly),
                $data['header'],
                $data['consumptions'],
                $data['expenses'],
            );
        } catch (InvalidProductAssemblyException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('production.product-assemblies.show', $batch)
            ->with('success', 'Assembly Draft updated.');
    }

    public function complete(Request $request, int $productAssembly): RedirectResponse
    {
        try {
            $this->assemblyService->complete($this->record($productAssembly), $request->user()?->id);
        } catch (InvalidProductAssemblyException|StockPostingException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            'Assembly completed. Components were deducted and good Finished Product stock was received. '
                .'No Finance journal was created.',
        );
    }

    public function destroy(int $productAssembly): JsonResponse
    {
        try {
            $this->assemblyService->deleteDraft($this->record($productAssembly));
        } catch (InvalidProductAssemblyException $exception) {
            return response()->json(['success' => false, 'msg' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'msg' => 'Assembly Draft deleted.']);
    }

    public function bomPlan(Request $request, ProductBom $productBom): JsonResponse
    {
        abort_unless($productBom->status === 'ACTIVE', 404);
        $request->validate([
            'planned_quantity' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);
        $warehouseId = $request->integer('warehouse_id') ?: null;
        if ($warehouseId !== null) {
            $allowed = DB::table('warehouses')
                ->where('id', $warehouseId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->when(
                    current_branch_id(),
                    fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId),
                )
                ->exists();
            abort_unless($allowed, 404);
        }
        $productBom->loadMissing('finishedProduct.baseUnit');

        return response()->json([
            'bom' => [
                'product_name' => $productBom->finishedProduct?->name,
                'product_code' => $productBom->finishedProduct?->product_code,
                'version' => $productBom->version,
                'output_unit' => $productBom->finishedProduct?->baseUnit?->code,
            ],
            'consumptions' => $this->assemblyService->plannedConsumptions(
                $productBom,
                $request->input('planned_quantity'),
                $warehouseId,
            ),
        ]);
    }

    private function formView(ProductAssemblyBatch $batch, string $title, string $method): View
    {
        $warehouses = DB::table('warehouses as warehouse')
            ->join('branches as branch', 'branch.id', '=', 'warehouse.branch_id')
            ->whereNull('warehouse.deleted_at')
            ->whereNull('branch.deleted_at')
            ->where('warehouse.is_active', true)
            ->where('branch.is_active', true)
            ->when(current_branch_id(), fn (Builder $query, int $branchId) => $query->where('warehouse.branch_id', $branchId))
            ->orderBy('branch.name')->orderBy('warehouse.name')
            ->get(['warehouse.id', 'warehouse.branch_id', 'warehouse.name', 'warehouse.code', 'branch.name as branch_name']);
        $boms = ProductBom::query()
            ->with('finishedProduct.baseUnit')
            ->where(function (EloquentBuilder $query) use ($batch): void {
                $query->where('status', 'ACTIVE')
                    ->where(fn (EloquentBuilder $valid) => $valid->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()));
                if ($batch->exists) {
                    $query->orWhereKey($batch->product_bom_id);
                }
            })
            ->orderByDesc('id')->get();

        return view('backend.modules.product_assembly.form', [
            'batch' => $batch,
            'title' => $title,
            'method' => $method,
            'action' => $method === 'POST'
                ? route('production.product-assemblies.store')
                : route('production.product-assemblies.update', $batch),
            'warehouses' => $warehouses,
            'boms' => $boms,
            'expenseCategories' => ProductionExpenseCategory::query()->where('is_active', true)->orderBy('name')->get(),
            'qcStatuses' => ProductionQcStatus::cases(),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'product_bom_id' => ['required', 'integer', 'exists:product_boms,id'],
            'input_warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where($this->availableWarehouseRule()),
            ],
            'output_warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where($this->availableWarehouseRule()),
            ],
            'assembly_date' => ['required', 'date'],
            'planned_quantity' => ['required', 'numeric', 'gt:0'],
            'good_quantity' => ['required', 'numeric', 'min:0'],
            'reject_quantity' => ['required', 'numeric', 'min:0'],
            'qc_status' => ['required', Rule::enum(ProductionQcStatus::class)],
            'qc_note' => ['nullable', 'string', 'max:5000'],
            'consumptions' => ['required', 'array', 'min:1'],
            'consumptions.*.component_type' => ['required', Rule::in(['RAW_MATERIAL', 'PART'])],
            'consumptions.*.raw_material_id' => ['nullable', 'integer'],
            'consumptions.*.part_id' => ['nullable', 'integer'],
            'consumptions.*.unit_id' => ['required', 'integer'],
            'consumptions.*.quantity' => ['required', 'numeric', 'gt:0'],
            'expenses' => ['nullable', 'array'],
            'expenses.*.production_expense_category_id' => ['nullable', 'integer'],
            'expenses.*.amount' => ['nullable', 'numeric', 'gt:0'],
            'expenses.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        return [
            'header' => [
                'product_bom_id' => $data['product_bom_id'],
                'input_warehouse_id' => $data['input_warehouse_id'],
                'output_warehouse_id' => $data['output_warehouse_id'],
                'assembly_date' => $data['assembly_date'],
                'planned_quantity' => $data['planned_quantity'],
                'good_quantity' => $data['good_quantity'],
                'reject_quantity' => $data['reject_quantity'],
                'qc_status' => $data['qc_status'],
                'qc_note' => $data['qc_note'] ?? null,
            ],
            'consumptions' => $data['consumptions'],
            'expenses' => array_values(array_filter(
                $data['expenses'] ?? [],
                fn (array $row): bool => filled($row['production_expense_category_id'] ?? null)
                    || filled($row['amount'] ?? null)
                    || filled($row['note'] ?? null),
            )),
        ];
    }

    private function record(int $id): ProductAssemblyBatch
    {
        $batch = ProductAssemblyBatch::query()
            ->whereKey($id)
            ->when(current_branch_id(), fn (EloquentBuilder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->with([
                'bom.finishedProduct.baseUnit', 'branch', 'inputWarehouse', 'outputWarehouse',
                'consumptions.rawMaterial.baseUnit', 'consumptions.part.baseUnit', 'consumptions.unit',
                'expenses.category', 'creator', 'completedBy',
            ])->first();
        abort_unless($batch, 404);

        return $batch;
    }

    private function statusBadge(ProductAssemblyStatus $status): string
    {
        $class = $status === ProductAssemblyStatus::Completed ? 'bg-dark-success-gradient' : 'bg-dark-warning-gradient';

        return '<span class="badge text-sm fw-semibold '.$class.' px-12 py-7 radius-4 text-white">'.e($status->label()).'</span>';
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

    private function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function availableWarehouseRule(): \Closure
    {
        return function ($query): void {
            $query->whereNull('deleted_at')->where('is_active', true);
            if ($branchId = current_branch_id()) {
                $query->where('branch_id', $branchId);
            }
        };
    }
}
