<?php

namespace App\Http\Controllers\backend;

use App\Enums\Inventory\StockDomain;
use App\Enums\Inventory\StockTransferStatus;
use App\Exceptions\Inventory\InvalidStockTransferException;
use App\Exceptions\Inventory\StockPostingException;
use App\Http\Controllers\Controller;
use App\Models\Inventory\StockTransfer;
use App\Services\Inventory\StockTransferService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockTransferController extends Controller
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

    public function __construct(private readonly StockTransferService $stockTransfer) {}

    public function index(): View
    {
        return view('backend.modules.stock_transfer.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['st.id', 'st.transfer_no', 'st.transfer_at', 'sw.name', 'dw.name', 'line_count', 'st.status'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));
        $status = strtoupper(trim((string) $request->input('status', '')));

        $query = DB::table('stock_transfers as st')
            ->join('warehouses as sw', 'sw.id', '=', 'st.source_warehouse_id')
            ->join('branches as sb', 'sb.id', '=', 'sw.branch_id')
            ->join('warehouses as dw', 'dw.id', '=', 'st.destination_warehouse_id')
            ->join('branches as db', 'db.id', '=', 'dw.branch_id')
            ->leftJoinSub($this->lineCountQuery(), 'lc', 'lc.stock_transfer_id', '=', 'st.id')
            ->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('st.branch_id', $branchId))
            ->select([
                'st.id', 'st.transfer_no', 'st.transfer_at', 'st.status',
                'sw.name as source_name', 'sw.code as source_code', 'sb.name as source_branch',
                'dw.name as destination_name', 'dw.code as destination_code', 'db.name as destination_branch',
                DB::raw('COALESCE(lc.line_count, 0) as line_count'),
            ]);

        $total = (clone $query)->count();

        if (in_array($status, array_column(StockTransferStatus::cases(), 'value'), true)) {
            $query->where('st.status', $status);
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('st.transfer_no', 'like', "%{$search}%")
                    ->orWhere('sw.name', 'like', "%{$search}%")
                    ->orWhere('sw.code', 'like', "%{$search}%")
                    ->orWhere('dw.name', 'like', "%{$search}%")
                    ->orWhere('dw.code', 'like', "%{$search}%")
                    ->orWhere('sb.name', 'like', "%{$search}%")
                    ->orWhere('db.name', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $rows = $query->orderBy($columns[$orderIndex] ?? 'st.id', $orderDirection)
            ->skip($start)->take($length)->get();
        $user = $request->user();
        $canEdit = $user?->canDo('inventory.stock-transfers.update', 'edit') ?? false;
        $canDelete = $user?->canDo('inventory.stock-transfers.destroy', 'delete') ?? false;

        $data = $rows->map(function (object $row) use ($canEdit, $canDelete): array {
            $status = StockTransferStatus::from($row->status);
            $statusClass = match ($status) {
                StockTransferStatus::Draft => 'bg-dark-warning-gradient',
                StockTransferStatus::InTransit => 'bg-dark-info-gradient',
                StockTransferStatus::Posted => 'bg-dark-success-gradient',
                StockTransferStatus::Void => 'bg-dark-danger-gradient',
            };
            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">';
            $actions .= '<a href="'.e(route('inventory.stock-transfers.show', $row->id)).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main" title="View"><iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';

            if ($canEdit && $status === StockTransferStatus::Draft) {
                $actions .= '<a href="'.e(route('inventory.stock-transfers.edit', $row->id)).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main" title="Edit"><iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }
            if ($canDelete && $status === StockTransferStatus::Draft) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-stock-transfer-delete" data-url="'.e(route('inventory.stock-transfers.destroy', $row->id)).'" title="Delete"><iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
            }
            $actions .= '</div>';

            return [
                $row->id,
                '<a class="fw-semibold text-primary-600" href="'.e(route('inventory.stock-transfers.show', $row->id)).'">'.e($row->transfer_no).'</a>',
                e(date('d M Y, h:i A', strtotime($row->transfer_at))),
                '<strong>'.e($row->source_name).'</strong><br><code>'.e($row->source_code).'</code> · '.e($row->source_branch),
                '<strong>'.e($row->destination_name).'</strong><br><code>'.e($row->destination_code).'</code> · '.e($row->destination_branch),
                '<span class="fw-semibold">'.(int) $row->line_count.'</span>',
                '<span class="badge text-sm fw-semibold '.$statusClass.' px-12 py-7 radius-4 text-white">'.e($status->label()).'</span>',
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
        return $this->formView(null);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $transfer = $this->stockTransfer->createDraft($data, $data['lines'], $request->user()?->id);
        } catch (InvalidStockTransferException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('inventory.stock-transfers.show', $transfer)->with('success', 'Stock transfer draft created successfully.');
    }

    public function show(int $stockTransfer): View
    {
        $transfer = $this->record($stockTransfer);

        return view('backend.modules.stock_transfer.show', [
            'transfer' => $transfer,
            'lines' => $this->documentLines($transfer),
        ]);
    }

    public function edit(int $stockTransfer): View
    {
        $transfer = $this->record($stockTransfer);
        abort_unless($transfer->status === StockTransferStatus::Draft, 409, 'Only a draft stock transfer can be edited.');

        return $this->formView($transfer);
    }

    public function update(Request $request, int $stockTransfer): RedirectResponse
    {
        $transfer = $this->record($stockTransfer);
        $data = $this->validated($request);

        try {
            $transfer = $this->stockTransfer->updateDraft($transfer, $data, $data['lines']);
        } catch (InvalidStockTransferException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('inventory.stock-transfers.show', $transfer)->with('success', 'Stock transfer draft updated successfully.');
    }

    public function post(Request $request, int $stockTransfer): RedirectResponse
    {
        $transfer = $this->record($stockTransfer);

        try {
            $this->stockTransfer->post($transfer, $request->user()?->id);
        } catch (InvalidStockTransferException|StockPostingException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Stock transfer posted successfully. Both warehouse balances are updated.');
    }

    public function destroy(int $stockTransfer): JsonResponse
    {
        $transfer = $this->record($stockTransfer);

        try {
            $this->stockTransfer->deleteDraft($transfer);
        } catch (InvalidStockTransferException $exception) {
            return response()->json(['success' => false, 'msg' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'msg' => 'Stock transfer draft deleted successfully.']);
    }

    public function itemSelect2(Request $request): JsonResponse
    {
        $config = $this->domain((string) $request->input('domain'));
        $search = trim((string) $request->input('q', ''));
        $query = DB::table($config['item_table'].' as i')
            ->join('units as u', 'u.id', '=', 'i.base_unit_id')
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
            ->map(fn (object $item): array => [
                'id' => $item->id,
                'text' => $item->name.' ('.$item->code.') — '.$item->unit_code,
            ]);

        return response()->json(['results' => $results]);
    }

    public function unitSelect2(Request $request): JsonResponse
    {
        $config = $this->domain((string) $request->input('domain'));
        $itemId = (int) $request->input('item_id');
        $item = DB::table($config['item_table'].' as i')
            ->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->where('i.id', $itemId)->whereNull('i.deleted_at')->where('i.is_active', true)
            ->whereNull('u.deleted_at')->where('u.is_active', true)
            ->first(['u.id', 'u.name', 'u.code', 'u.precision']);

        if (! $item) {
            return response()->json(['results' => []]);
        }

        $results = collect([[
            'id' => $item->id,
            'text' => $item->name.' ('.$item->code.') — Base unit',
            'precision' => (int) $item->precision,
        ]])->concat(
            DB::table($config['unit_table'].' as m')
                ->join('units as u', 'u.id', '=', 'm.unit_id')
                ->where('m.'.$config['item_column'], $itemId)
                ->whereNull('u.deleted_at')->where('u.is_active', true)
                ->orderBy('u.name')
                ->get(['u.id', 'u.name', 'u.code', 'u.precision', 'm.conversion_to_base'])
                ->map(fn (object $unit): array => [
                    'id' => $unit->id,
                    'text' => $unit->name.' ('.$unit->code.') — 1 '.$unit->code.' = '.$this->formatQuantity($unit->conversion_to_base).' base',
                    'precision' => (int) $unit->precision,
                ])
        )->values();

        return response()->json(['results' => $results]);
    }

    private function formView(?StockTransfer $transfer): View
    {
        $allWarehouses = DB::table('warehouses as w')
            ->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->whereNull('w.deleted_at')->whereNull('b.deleted_at')
            ->where('w.is_active', true)->where('b.is_active', true)
            ->orderBy('b.name')->orderByDesc('w.is_default')->orderBy('w.name')
            ->get(['w.id', 'w.branch_id', 'w.name', 'w.code', 'b.name as branch_name']);
        $sourceWarehouses = current_branch_id()
            ? $allWarehouses->where('branch_id', current_branch_id())->values()
            : $allWarehouses;

        return view('backend.modules.stock_transfer.form', [
            'transfer' => $transfer,
            'lines' => $transfer ? $this->documentLines($transfer) : [],
            'sourceWarehouses' => $sourceWarehouses,
            'destinationWarehouses' => $allWarehouses,
            'domains' => self::DOMAINS,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'source_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(function ($query): void {
                $query->whereNull('deleted_at')->where('is_active', true);
                if ($branchId = current_branch_id()) {
                    $query->where('branch_id', $branchId);
                }
            })],
            'destination_warehouse_id' => [
                'required', 'integer', 'different:source_warehouse_id',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'transfer_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.domain' => ['required', Rule::enum(StockDomain::class)],
            'lines.*.item_id' => ['required', 'integer', 'min:1'],
            'lines.*.unit_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.999999'],
        ], [
            'destination_warehouse_id.different' => 'Source and destination warehouses must be different.',
        ]);
    }

    private function record(int $id): StockTransfer
    {
        $record = StockTransfer::query()->whereKey($id)
            ->when(current_branch_id(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->with([
                'branch', 'sourceWarehouse.branch', 'destinationWarehouse.branch', 'createdBy', 'postedBy',
                'rawMaterialItems.rawMaterial.baseUnit', 'rawMaterialItems.unit',
                'partItems.part.baseUnit', 'partItems.unit',
                'finishedProductItems.finishedProduct.baseUnit', 'finishedProductItems.unit',
            ])->first();
        abort_unless($record, 404);

        return $record;
    }

    /** @return array<int, array<string, mixed>> */
    private function documentLines(StockTransfer $transfer): array
    {
        $lines = [];

        foreach (self::DOMAINS as $domain => $config) {
            foreach ($transfer->{$config['relation']} as $line) {
                $item = $line->{$config['item_relation']};
                $lines[] = [
                    'id' => $line->id,
                    'domain' => $domain,
                    'domain_label' => $config['label'],
                    'item_id' => $item->id,
                    'item_text' => $item->name.' ('.$item->{$config['code_column']}.')',
                    'unit_id' => $line->unit_id,
                    'unit_text' => $line->unit->name.' ('.$line->unit->code.')',
                    'quantity' => $line->quantity,
                    'quantity_input' => $this->formatQuantityForUnit($line->quantity, (int) $line->unit->precision),
                    'unit_precision' => (int) $line->unit->precision,
                    'base_quantity' => $line->base_quantity,
                    'base_unit_code' => $item->baseUnit->code,
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
        $union = DB::table('raw_material_transfer_items')->select('stock_transfer_id')
            ->unionAll(DB::table('part_transfer_items')->select('stock_transfer_id'))
            ->unionAll(DB::table('finished_product_transfer_items')->select('stock_transfer_id'));

        return DB::query()->fromSub($union, 'all_lines')
            ->select('stock_transfer_id', DB::raw('COUNT(*) as line_count'))
            ->groupBy('stock_transfer_id');
    }

    private function formatQuantity(string|float|int $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 6, '.', ''), '0'), '.');
    }

    private function formatQuantityForUnit(string|float|int $quantity, int $precision): string
    {
        $precision = max(0, min(6, $precision));
        [$integer, $fraction] = array_pad(explode('.', (string) $quantity, 2), 2, '');

        return $precision === 0
            ? $integer
            : $integer.'.'.substr(str_pad($fraction, $precision, '0'), 0, $precision);
    }
}
