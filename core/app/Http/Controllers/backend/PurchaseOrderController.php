<?php

namespace App\Http\Controllers\backend;

use App\Enums\Purchase\PurchaseOrderStatus;
use App\Exceptions\Purchase\InvalidPurchaseOrderException;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseOrder;
use App\Services\Purchase\PurchaseOrderService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    private const DOMAINS = [
        'raw_material' => [
            'label' => 'Raw Material', 'item_table' => 'raw_materials', 'item_column' => 'raw_material_id',
            'code_column' => 'material_code', 'unit_table' => 'raw_material_units', 'supplier_table' => 'raw_material_suppliers',
            'category_table' => 'raw_material_categories', 'category_column' => 'raw_material_category_id',
        ],
        'part' => [
            'label' => 'Part', 'item_table' => 'parts', 'item_column' => 'part_id',
            'code_column' => 'part_code', 'unit_table' => 'part_units', 'supplier_table' => 'part_suppliers',
            'category_table' => 'part_categories', 'category_column' => 'part_category_id',
        ],
    ];

    public function __construct(private readonly PurchaseOrderService $purchaseOrder) {}

    public function index(): View
    {
        return view('backend.modules.purchase_order.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['po.id', 'po.order_no', 'po.order_date', 's.name', 'w.name', 'line_count', 'po.total', 'po.status', 'po.id'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 1);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));
        $status = strtoupper(trim((string) $request->input('status', '')));

        $query = DB::table('purchase_order as po')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->join('warehouses as w', 'w.id', '=', 'po.warehouse_id')
            ->join('branches as b', 'b.id', '=', 'po.branch_id')
            ->leftJoinSub(
                DB::table('purchase_order_item')->select('purchase_order_id', DB::raw('COUNT(*) as line_count'))->groupBy('purchase_order_id'),
                'lines', 'lines.purchase_order_id', '=', 'po.id'
            )
            ->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('po.branch_id', $branchId))
            ->select([
                'po.id', 'po.order_no', 'po.order_date', 'po.expected_date', 'po.status', 'po.total',
                's.name as supplier_name', 's.code as supplier_code', 'w.name as warehouse_name', 'w.code as warehouse_code',
                'b.name as branch_name', DB::raw('COALESCE(lines.line_count, 0) as line_count'),
            ]);

        $total = (clone $query)->count();
        if (PurchaseOrderStatus::tryFrom($status)) {
            $query->where('po.status', $status);
        }
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('po.order_no', 'like', "%{$search}%")
                    ->orWhere('s.name', 'like', "%{$search}%")
                    ->orWhere('s.code', 'like', "%{$search}%")
                    ->orWhere('w.name', 'like', "%{$search}%")
                    ->orWhere('w.code', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $rows = $query->orderBy($columns[$orderIndex] ?? 'po.id', $orderDirection)->skip($start)->take($length)->get();
        $user = $request->user();
        $canEdit = $user?->canDo('purchase.orders.update', 'edit') ?? false;
        $canDelete = $user?->canDo('purchase.orders.destroy', 'delete') ?? false;

        $data = $rows->map(function (object $row) use ($canEdit, $canDelete): array {
            $status = PurchaseOrderStatus::from($row->status);
            $statusClass = match ($status) {
                PurchaseOrderStatus::Draft => 'bg-dark-warning-gradient',
                PurchaseOrderStatus::Approved => 'bg-dark-info-gradient',
                PurchaseOrderStatus::Partial => 'bg-dark-lilac-gradient',
                PurchaseOrderStatus::Received => 'bg-dark-success-gradient',
                PurchaseOrderStatus::Cancelled => 'bg-dark-danger-gradient',
            };
            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">';
            $actions .= '<a href="'.e(route('purchase.orders.show', $row->id)).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main" title="View"><iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';
            if ($canEdit && $status === PurchaseOrderStatus::Draft) {
                $actions .= '<a href="'.e(route('purchase.orders.edit', $row->id)).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main" title="Edit"><iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }
            if ($canDelete && $status === PurchaseOrderStatus::Draft) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-purchase-order-delete" data-url="'.e(route('purchase.orders.destroy', $row->id)).'" title="Delete"><iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
            }
            $actions .= '</div>';

            return [
                $row->id,
                '<a class="fw-semibold text-primary-600" href="'.e(route('purchase.orders.show', $row->id)).'">'.e($row->order_no).'</a>',
                e(date('d M Y', strtotime($row->order_date))),
                '<strong>'.e($row->supplier_name).'</strong>'.($row->supplier_code ? '<br><code>'.e($row->supplier_code).'</code>' : ''),
                '<strong>'.e($row->warehouse_name).'</strong> <code>'.e($row->warehouse_code).'</code><br><small class="text-muted">'.e($row->branch_name).'</small>',
                '<span class="fw-semibold">'.(int) $row->line_count.'</span>',
                '<span class="fw-semibold">'.e(number_format((float) $row->total, 2)).'</span>',
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
            $order = $this->purchaseOrder->createDraft($data, $data['lines'], $request->user()?->id);
        } catch (InvalidPurchaseOrderException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('purchase.orders.show', $order)->with('success', 'Purchase order draft created successfully.');
    }

    public function show(int $purchaseOrder): View
    {
        $order = $this->record($purchaseOrder);

        return view('backend.modules.purchase_order.show', ['order' => $order, 'lines' => $this->documentLines($order)]);
    }

    public function edit(int $purchaseOrder): View
    {
        $order = $this->record($purchaseOrder);
        abort_unless($order->status === PurchaseOrderStatus::Draft, 409, 'Only a draft purchase order can be edited.');

        return $this->formView($order);
    }

    public function update(Request $request, int $purchaseOrder): RedirectResponse
    {
        $order = $this->record($purchaseOrder);
        $data = $this->validated($request);
        try {
            $order = $this->purchaseOrder->updateDraft($order, $data, $data['lines']);
        } catch (InvalidPurchaseOrderException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('purchase.orders.show', $order)->with('success', 'Purchase order draft updated successfully.');
    }

    public function approve(Request $request, int $purchaseOrder): RedirectResponse
    {
        try {
            $this->purchaseOrder->approve($this->record($purchaseOrder), $request->user()?->id);
        } catch (InvalidPurchaseOrderException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Purchase order approved successfully.');
    }

    public function cancel(int $purchaseOrder): RedirectResponse
    {
        try {
            $this->purchaseOrder->cancel($this->record($purchaseOrder));
        } catch (InvalidPurchaseOrderException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Purchase order cancelled successfully.');
    }

    public function destroy(int $purchaseOrder): JsonResponse
    {
        try {
            $this->purchaseOrder->deleteDraft($this->record($purchaseOrder));
        } catch (InvalidPurchaseOrderException $exception) {
            return response()->json(['success' => false, 'msg' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'msg' => 'Purchase order draft deleted successfully.']);
    }

    public function itemSelect2(Request $request): JsonResponse
    {
        $domain = (string) $request->input('domain');
        abort_unless(isset(self::DOMAINS[$domain]), 404);
        $supplierId = (int) $request->input('supplier_id');
        if ($supplierId < 1) {
            return response()->json(['results' => [], 'pagination' => ['more' => false]]);
        }
        $supplierAvailable = DB::table('suppliers')->where('id', $supplierId)
            ->whereNull('deleted_at')->where('is_active', true)->exists();
        if (! $supplierAvailable) {
            return response()->json(['results' => [], 'pagination' => ['more' => false]]);
        }
        $config = self::DOMAINS[$domain];
        $search = trim((string) $request->input('q', ''));
        $categoryId = max((int) $request->input('category_id'), 0);
        $page = max((int) $request->input('page', 1), 1);
        $perPage = 20;
        $query = DB::table($config['item_table'].' as i')
            ->leftJoin($config['supplier_table'].' as sm', function ($join) use ($config, $supplierId): void {
                $join->on('sm.'.$config['item_column'], '=', 'i.id')
                    ->where('sm.supplier_id', '=', $supplierId);
            })
            ->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->join($config['category_table'].' as c', 'c.id', '=', 'i.'.$config['category_column'])
            ->whereNull('i.deleted_at')->where('i.is_active', true)
            ->whereNull('c.deleted_at')->where('c.is_active', true);
        if ($domain === 'part') {
            $query->whereIn('i.procurement_mode', ['BUY', 'BOTH']);
        }
        if ($categoryId > 0) {
            $query->where('i.'.$config['category_column'], $categoryId);
        }
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $config): void {
                $builder->where('i.name', 'like', "%{$search}%")
                    ->orWhere('i.'.$config['code_column'], 'like', "%{$search}%")
                    ->orWhere('sm.supplier_code', 'like', "%{$search}%");
            });
        }
        $total = (clone $query)->count();
        $results = $query->orderByRaw('CASE WHEN sm.id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('sm.is_preferred')->orderBy('i.name')
            ->skip(($page - 1) * $perPage)->take($perPage)
            ->get([
                'i.id', 'i.name', 'i.'.$config['code_column'].' as code', 'i.image_path',
                'u.code as unit_code', 'u.id as base_unit_id', 'u.precision as unit_precision',
                'c.name as category_name', 'sm.id as supplier_mapping_id', 'sm.supplier_code', 'sm.is_preferred',
            ])
            ->map(fn (object $item): array => [
                'id' => $item->id,
                'text' => $item->name.' ('.$item->code.') — '.$item->unit_code.($item->supplier_code ? ' · Supplier code: '.$item->supplier_code : ''),
                'domain' => $domain,
                'domain_label' => $config['label'],
                'name' => $item->name,
                'code' => $item->code,
                'image_url' => $item->image_path ? image($item->image_path) : asset('theme/admin/assets/images/datatable/purchase1.svg'),
                'unit_code' => $item->unit_code,
                'base_unit_id' => (int) $item->base_unit_id,
                'unit_precision' => (int) $item->unit_precision,
                'category_name' => $item->category_name,
                'supplier_code' => $item->supplier_code,
                'is_mapped' => $item->supplier_mapping_id !== null,
                'is_preferred' => (bool) $item->is_preferred,
            ]);

        return response()->json([
            'results' => $results,
            'pagination' => ['more' => $page * $perPage < $total],
        ]);
    }

    public function unitSelect2(Request $request): JsonResponse
    {
        $domain = (string) $request->input('domain');
        abort_unless(isset(self::DOMAINS[$domain]), 404);
        $config = self::DOMAINS[$domain];
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
                ->where('m.'.$config['item_column'], $itemId)->whereIn('m.usage_type', ['PURCHASE', 'ALL'])
                ->whereNull('u.deleted_at')->where('u.is_active', true)->orderBy('u.name')
                ->get(['u.id', 'u.name', 'u.code', 'u.precision', 'm.conversion_to_base'])
                ->map(fn (object $unit): array => [
                    'id' => $unit->id,
                    'text' => $unit->name.' ('.$unit->code.') — 1 '.$unit->code.' = '.$this->formatQuantity($unit->conversion_to_base).' base',
                    'precision' => (int) $unit->precision,
                ])
        )->values();

        return response()->json(['results' => $results]);
    }

    private function formView(?PurchaseOrder $order): View
    {
        $suppliers = DB::table('suppliers')->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']);
        $warehouses = DB::table('warehouses as w')->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->whereNull('w.deleted_at')->whereNull('b.deleted_at')->where('w.is_active', true)->where('b.is_active', true)
            ->when(current_branch_id(), fn (Builder $query, int $branchId) => $query->where('w.branch_id', $branchId))
            ->orderBy('b.name')->orderBy('w.name')->get(['w.id', 'w.name', 'w.code', 'b.name as branch_name']);

        return view('backend.modules.purchase_order.form', [
            'order' => $order,
            'lines' => $order ? $this->documentLines($order) : [],
            'suppliers' => $suppliers,
            'warehouses' => $warehouses,
            'domains' => self::DOMAINS,
            'catalogCategories' => [
                'raw_material' => DB::table('raw_material_categories')->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'part' => DB::table('part_categories')->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true))],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(function ($query): void {
                $query->whereNull('deleted_at')->where('is_active', true);
                if ($branchId = current_branch_id()) {
                    $query->where('branch_id', $branchId);
                }
            })],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'discount_type' => ['required', Rule::in(['FLAT', 'PERCENT'])],
            'discount_value' => [
                'required', 'numeric', 'min:0',
                Rule::when($request->input('discount_type') === 'PERCENT', ['max:100'], ['max:9999999999999999.9999']),
            ],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.9999'],
            'note' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.domain' => ['required', Rule::in(array_keys(self::DOMAINS))],
            'lines.*.item_id' => ['required', 'integer', 'min:1'],
            'lines.*.unit_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.999999'],
            'lines.*.unit_rate' => ['required', 'numeric', 'gt:0', 'max:9999999999999999.9999'],
        ], [
            'expected_date.after_or_equal' => 'Expected date cannot be earlier than the order date.',
        ]);
    }

    private function record(int $id): PurchaseOrder
    {
        $order = PurchaseOrder::query()->whereKey($id)
            ->when(current_branch_id(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->with(['branch', 'supplier', 'warehouse', 'createdBy', 'approvedBy', 'items.rawMaterial.baseUnit', 'items.part.baseUnit', 'items.unit'])
            ->first();
        abort_unless($order, 404);

        return $order;
    }

    /** @return array<int, array<string, mixed>> */
    private function documentLines(PurchaseOrder $order): array
    {
        return $order->items->map(function ($line): array {
            $domain = $line->raw_material_id ? 'raw_material' : 'part';
            $config = self::DOMAINS[$domain];
            $item = $line->raw_material_id ? $line->rawMaterial : $line->part;

            return [
                'id' => $line->id,
                'domain' => $domain,
                'domain_label' => $config['label'],
                'item_id' => $item->id,
                'item_text' => $item->name.' ('.$item->{$config['code_column']}.')',
                'unit_id' => $line->unit_id,
                'unit_text' => $line->unit->name.' ('.$line->unit->code.')',
                'unit_precision' => (int) $line->unit->precision,
                'quantity' => $line->quantity,
                'quantity_input' => $this->formatQuantityForUnit($line->quantity, (int) $line->unit->precision),
                'base_quantity' => $line->base_quantity,
                'base_unit_code' => $item->baseUnit->code,
                'conversion_to_base' => $line->conversion_to_base,
                'unit_rate' => $line->unit_rate,
                'line_total' => $line->line_total,
                'received_base_quantity' => $line->received_base_quantity,
            ];
        })->all();
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
