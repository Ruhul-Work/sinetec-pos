<?php

namespace App\Http\Controllers\backend;

use App\Enums\Inventory\StockAdjustmentStatus;
use App\Enums\Inventory\StockDomain;
use App\Exceptions\Inventory\InvalidStockAdjustmentException;
use App\Exceptions\Inventory\StockPostingException;
use App\Http\Controllers\Controller;
use App\Models\Inventory\StockAdjustment;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockAdjustmentController extends Controller
{
    private const DOMAINS = [
        'raw_material' => [
            'label' => 'Raw Material', 'item_table' => 'raw_materials', 'item_column' => 'raw_material_id',
            'code_column' => 'material_code', 'unit_table' => 'raw_material_units',
            'relation' => 'rawMaterialItems', 'item_relation' => 'rawMaterial',
        ],
        'part' => [
            'label' => 'Part', 'item_table' => 'parts', 'item_column' => 'part_id',
            'code_column' => 'part_code', 'unit_table' => 'part_units',
            'relation' => 'partItems', 'item_relation' => 'part',
        ],
        'finished_product' => [
            'label' => 'Finished Product', 'item_table' => 'finished_products', 'item_column' => 'finished_product_id',
            'code_column' => 'product_code', 'unit_table' => 'finished_product_units',
            'relation' => 'finishedProductItems', 'item_relation' => 'finishedProduct',
        ],
    ];

    private const TYPE_LABELS = [
        'GAIN' => 'Stock Gain',
        'LOSS' => 'Stock Loss',
        'DAMAGE' => 'Damage',
        'WASTAGE' => 'Wastage',
        'CORRECTION' => 'Correction',
    ];

    public function __construct(private readonly StockAdjustmentService $stockAdjustment) {}

    public function index(): View
    {
        return view('backend.modules.stock_adjustment.index', ['types' => self::TYPE_LABELS]);
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['sa.id', 'sa.adjustment_no', 'sa.adjustment_at', 'sa.adjustment_type', 'b.name', 'w.name', 'line_count', 'sa.status'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));
        $status = strtoupper(trim((string) $request->input('status', '')));
        $type = strtoupper(trim((string) $request->input('type', '')));

        $query = DB::table('stock_adjustments as sa')
            ->join('branches as b', 'b.id', '=', 'sa.branch_id')
            ->join('warehouses as w', 'w.id', '=', 'sa.warehouse_id')
            ->leftJoinSub($this->lineCountQuery(), 'lc', 'lc.stock_adjustment_id', '=', 'sa.id')
            ->whereIn('sa.adjustment_type', StockAdjustmentService::TYPES)
            ->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('sa.branch_id', $branchId))
            ->select([
                'sa.id', 'sa.adjustment_no', 'sa.adjustment_at', 'sa.adjustment_type', 'sa.status', 'sa.reason',
                'b.name as branch_name', 'w.name as warehouse_name', 'w.code as warehouse_code',
                DB::raw('COALESCE(lc.line_count, 0) as line_count'),
            ]);

        $total = (clone $query)->count();
        if (in_array($status, array_column(StockAdjustmentStatus::cases(), 'value'), true)) {
            $query->where('sa.status', $status);
        }
        if (isset(self::TYPE_LABELS[$type])) {
            $query->where('sa.adjustment_type', $type);
        }
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('sa.adjustment_no', 'like', "%{$search}%")
                    ->orWhere('sa.reason', 'like', "%{$search}%")
                    ->orWhere('b.name', 'like', "%{$search}%")
                    ->orWhere('w.name', 'like', "%{$search}%")
                    ->orWhere('w.code', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $rows = $query->orderBy($columns[$orderIndex] ?? 'sa.id', $orderDirection)->skip($start)->take($length)->get();
        $user = $request->user();
        $canEdit = $user?->canDo('inventory.stock-adjustments.update', 'edit') ?? false;
        $canDelete = $user?->canDo('inventory.stock-adjustments.destroy', 'delete') ?? false;

        $data = $rows->map(function (object $row) use ($canEdit, $canDelete): array {
            $status = StockAdjustmentStatus::from($row->status);
            $statusClass = match ($status) {
                StockAdjustmentStatus::Draft => 'bg-dark-warning-gradient',
                StockAdjustmentStatus::Approved => 'bg-dark-info-gradient',
                StockAdjustmentStatus::Posted => 'bg-dark-success-gradient',
                StockAdjustmentStatus::Void => 'bg-dark-danger-gradient',
            };
            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">';
            $actions .= '<a href="'.e(route('inventory.stock-adjustments.show', $row->id)).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main" title="View"><iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';
            if ($canEdit && $status === StockAdjustmentStatus::Draft) {
                $actions .= '<a href="'.e(route('inventory.stock-adjustments.edit', $row->id)).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main" title="Edit"><iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }
            if ($canDelete && $status === StockAdjustmentStatus::Draft) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-stock-adjustment-delete" data-url="'.e(route('inventory.stock-adjustments.destroy', $row->id)).'" title="Delete"><iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
            }
            $actions .= '</div>';

            return [
                $row->id,
                '<a class="fw-semibold text-primary-600" href="'.e(route('inventory.stock-adjustments.show', $row->id)).'">'.e($row->adjustment_no).'</a>',
                e(date('d M Y, h:i A', strtotime($row->adjustment_at))),
                '<span class="badge bg-light text-dark">'.e(self::TYPE_LABELS[$row->adjustment_type] ?? $row->adjustment_type).'</span>',
                e($row->branch_name),
                '<strong>'.e($row->warehouse_name).'</strong><br><code>'.e($row->warehouse_code).'</code>',
                '<span class="fw-semibold">'.(int) $row->line_count.'</span>',
                '<span class="badge text-sm fw-semibold '.$statusClass.' px-12 py-7 radius-4 text-white">'.e($status->label()).'</span>',
                $actions,
            ];
        })->all();

        return response()->json(['draw' => $draw, 'iTotalRecords' => $total, 'iTotalDisplayRecords' => $filtered, 'aaData' => $data]);
    }

    public function create(): View
    {
        return $this->formView(null);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        try {
            $adjustment = $this->stockAdjustment->createDraft($data, $data['lines'], $request->user()?->id);
        } catch (InvalidStockAdjustmentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('inventory.stock-adjustments.show', $adjustment)->with('success', 'Stock adjustment draft created successfully.');
    }

    public function show(int $stockAdjustment): View
    {
        $adjustment = $this->record($stockAdjustment);

        return view('backend.modules.stock_adjustment.show', [
            'adjustment' => $adjustment,
            'lines' => $this->documentLines($adjustment),
            'typeLabel' => self::TYPE_LABELS[$adjustment->adjustment_type] ?? $adjustment->adjustment_type,
        ]);
    }

    public function edit(int $stockAdjustment): View
    {
        $adjustment = $this->record($stockAdjustment);
        abort_unless($adjustment->status === StockAdjustmentStatus::Draft, 409, 'Only a draft stock adjustment can be edited.');

        return $this->formView($adjustment);
    }

    public function update(Request $request, int $stockAdjustment): RedirectResponse
    {
        $adjustment = $this->record($stockAdjustment);
        $data = $this->validated($request);
        try {
            $adjustment = $this->stockAdjustment->updateDraft($adjustment, $data, $data['lines']);
        } catch (InvalidStockAdjustmentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('inventory.stock-adjustments.show', $adjustment)->with('success', 'Stock adjustment draft updated successfully.');
    }

    public function approve(Request $request, int $stockAdjustment): RedirectResponse
    {
        try {
            $this->stockAdjustment->approve($this->record($stockAdjustment), $request->user()?->id);
        } catch (InvalidStockAdjustmentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Stock adjustment approved. It is now ready to post.');
    }

    public function post(Request $request, int $stockAdjustment): RedirectResponse
    {
        try {
            $this->stockAdjustment->post($this->record($stockAdjustment), $request->user()?->id);
        } catch (InvalidStockAdjustmentException|StockPostingException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Stock adjustment posted successfully. Warehouse balances are updated.');
    }

    public function destroy(int $stockAdjustment): JsonResponse
    {
        try {
            $this->stockAdjustment->deleteDraft($this->record($stockAdjustment));
        } catch (InvalidStockAdjustmentException $exception) {
            return response()->json(['success' => false, 'msg' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'msg' => 'Stock adjustment draft deleted successfully.']);
    }

    public function itemSelect2(Request $request): JsonResponse
    {
        $config = $this->domain((string) $request->input('domain'));
        $search = trim((string) $request->input('q', ''));
        $query = DB::table($config['item_table'].' as i')->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->whereNull('i.deleted_at')->where('i.is_active', true);
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $config): void {
                $builder->where('i.name', 'like', "%{$search}%")
                    ->orWhere('i.'.$config['code_column'], 'like', "%{$search}%")
                    ->orWhere('i.barcode', 'like', "%{$search}%");
            });
        }
        $results = $query->orderBy('i.name')->limit(20)
            ->get(['i.id', 'i.name', 'i.'.$config['code_column'].' as code', 'u.code as unit_code'])
            ->map(fn (object $item): array => ['id' => $item->id, 'text' => $item->name.' ('.$item->code.') — '.$item->unit_code]);

        return response()->json(['results' => $results]);
    }

    public function unitSelect2(Request $request): JsonResponse
    {
        $config = $this->domain((string) $request->input('domain'));
        $itemId = (int) $request->input('item_id');
        $item = DB::table($config['item_table'].' as i')->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->where('i.id', $itemId)->whereNull('i.deleted_at')->where('i.is_active', true)
            ->whereNull('u.deleted_at')->where('u.is_active', true)
            ->first(['u.id', 'u.name', 'u.code', 'u.precision']);
        if (! $item) {
            return response()->json(['results' => []]);
        }
        $results = collect([[
            'id' => $item->id, 'text' => $item->name.' ('.$item->code.') — Base unit', 'precision' => (int) $item->precision,
        ]])->concat(
            DB::table($config['unit_table'].' as m')->join('units as u', 'u.id', '=', 'm.unit_id')
                ->where('m.'.$config['item_column'], $itemId)->whereNull('u.deleted_at')->where('u.is_active', true)
                ->orderBy('u.name')->get(['u.id', 'u.name', 'u.code', 'u.precision', 'm.conversion_to_base'])
                ->map(fn (object $unit): array => [
                    'id' => $unit->id,
                    'text' => $unit->name.' ('.$unit->code.') — 1 '.$unit->code.' = '.$this->formatQuantity($unit->conversion_to_base).' base',
                    'precision' => (int) $unit->precision,
                ])
        )->values();

        return response()->json(['results' => $results]);
    }

    private function formView(?StockAdjustment $adjustment): View
    {
        $warehouses = DB::table('warehouses as w')->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->whereNull('w.deleted_at')->whereNull('b.deleted_at')->where('w.is_active', true)->where('b.is_active', true)
            ->when(current_branch_id(), fn (Builder $query, int $branchId) => $query->where('w.branch_id', $branchId))
            ->orderBy('b.name')->orderByDesc('w.is_default')->orderBy('w.name')
            ->get(['w.id', 'w.name', 'w.code', 'b.name as branch_name']);

        return view('backend.modules.stock_adjustment.form', [
            'adjustment' => $adjustment,
            'lines' => $adjustment ? $this->documentLines($adjustment) : [],
            'warehouses' => $warehouses,
            'domains' => self::DOMAINS,
            'types' => self::TYPE_LABELS,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(function ($query): void {
                $query->whereNull('deleted_at')->where('is_active', true);
                if ($branchId = current_branch_id()) {
                    $query->where('branch_id', $branchId);
                }
            })],
            'adjustment_type' => ['required', Rule::in(array_keys(self::TYPE_LABELS))],
            'adjustment_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.domain' => ['required', Rule::enum(StockDomain::class)],
            'lines.*.item_id' => ['required', 'integer', 'min:1'],
            'lines.*.unit_id' => ['required', 'integer', 'min:1'],
            'lines.*.direction' => [Rule::requiredIf(fn () => strtoupper((string) $request->input('adjustment_type')) === 'CORRECTION'), 'nullable', Rule::in(['IN', 'OUT'])],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.999999'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.9999'],
            'lines.*.note' => ['nullable', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Please enter a reason for this stock adjustment.',
            'lines.*.direction.required' => 'Please select IN or OUT for every correction line.',
        ]);
    }

    private function record(int $id): StockAdjustment
    {
        $record = StockAdjustment::query()->whereKey($id)->whereIn('adjustment_type', StockAdjustmentService::TYPES)
            ->when(current_branch_id(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->with([
                'branch', 'warehouse', 'createdBy', 'approvedBy', 'postedBy',
                'rawMaterialItems.rawMaterial.baseUnit', 'rawMaterialItems.unit',
                'partItems.part.baseUnit', 'partItems.unit',
                'finishedProductItems.finishedProduct.baseUnit', 'finishedProductItems.unit',
            ])->first();
        abort_unless($record, 404);

        return $record;
    }

    /** @return array<int, array<string, mixed>> */
    private function documentLines(StockAdjustment $adjustment): array
    {
        $lines = [];
        foreach (self::DOMAINS as $domain => $config) {
            foreach ($adjustment->{$config['relation']} as $line) {
                $item = $line->{$config['item_relation']};
                $lines[] = [
                    'id' => $line->id,
                    'domain' => $domain,
                    'domain_label' => $config['label'],
                    'item_id' => $item->id,
                    'item_text' => $item->name.' ('.$item->{$config['code_column']}.')',
                    'unit_id' => $line->unit_id,
                    'unit_text' => $line->unit->name.' ('.$line->unit->code.')',
                    'unit_precision' => (int) $line->unit->precision,
                    'direction' => $line->direction->value,
                    'quantity' => $line->quantity,
                    'quantity_input' => $this->formatQuantityForUnit($line->quantity, (int) $line->unit->precision),
                    'base_quantity' => $line->base_quantity,
                    'base_unit_code' => $item->baseUnit->code,
                    'unit_cost' => $line->unit_cost,
                    'note' => $line->note,
                ];
            }
        }

        return $lines;
    }

    /** @return array<string, string> */
    private function domain(string $domain): array
    {
        abort_unless(isset(self::DOMAINS[$domain]), 404);

        return self::DOMAINS[$domain];
    }

    private function lineCountQuery(): Builder
    {
        $union = DB::table('raw_material_adjustment_items')->select('stock_adjustment_id')
            ->unionAll(DB::table('part_adjustment_items')->select('stock_adjustment_id'))
            ->unionAll(DB::table('finished_product_adjustment_items')->select('stock_adjustment_id'));

        return DB::query()->fromSub($union, 'all_lines')
            ->select('stock_adjustment_id', DB::raw('COUNT(*) as line_count'))->groupBy('stock_adjustment_id');
    }

    private function formatQuantity(string|float|int $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 6, '.', ''), '0'), '.');
    }

    private function formatQuantityForUnit(string|float|int $quantity, int $precision): string
    {
        $precision = max(0, min(6, $precision));
        [$integer, $fraction] = array_pad(explode('.', (string) $quantity, 2), 2, '');

        return $precision === 0 ? $integer : $integer.'.'.substr(str_pad($fraction, $precision, '0'), 0, $precision);
    }
}
