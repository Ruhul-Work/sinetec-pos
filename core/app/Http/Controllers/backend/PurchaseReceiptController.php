<?php

namespace App\Http\Controllers\backend;

use App\Enums\Purchase\PurchaseOrderStatus;
use App\Enums\Purchase\PurchaseReceiptStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Exceptions\Purchase\InvalidPurchaseReceiptException;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseReceipt;
use App\Services\Purchase\PurchaseReceiptService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class PurchaseReceiptController extends Controller
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

    public function __construct(private readonly PurchaseReceiptService $purchaseReceipt) {}

    public function index(): View
    {
        return view('backend.modules.purchase_receipt.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['pr.id', 'pr.receipt_no', 'pr.receipt_date', 's.name', 'w.name', 'po.order_no', 'line_count', 'pr.total', 'pr.status', 'pr.id'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $direction = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));
        $status = strtoupper(trim((string) $request->input('status', '')));
        $counts = DB::table('purchase_receipt_item')->select('purchase_receipt_id', DB::raw('COUNT(*) as line_count'))->groupBy('purchase_receipt_id');
        $query = DB::table('purchase_receipt as pr')->join('suppliers as s', 's.id', '=', 'pr.supplier_id')->join('warehouses as w', 'w.id', '=', 'pr.warehouse_id')->join('branches as b', 'b.id', '=', 'pr.branch_id')
            ->leftJoin('purchase_order as po', 'po.id', '=', 'pr.purchase_order_id')->leftJoinSub($counts, 'lc', 'lc.purchase_receipt_id', '=', 'pr.id')
            ->when(current_branch_id(), fn (Builder $q, int $branchId) => $q->where('pr.branch_id', $branchId))
            ->select(['pr.id', 'pr.receipt_no', 'pr.receipt_date', 'pr.supplier_invoice_no', 'pr.supplier_invoice_path', 'pr.status', 'pr.total', 's.name as supplier_name', 'w.name as warehouse_name', 'w.code as warehouse_code', 'b.name as branch_name', 'po.order_no', DB::raw('COALESCE(lc.line_count, 0) as line_count')]);
        $total = (clone $query)->count();
        if (PurchaseReceiptStatus::tryFrom($status)) {
            $query->where('pr.status', $status);
        }
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('pr.receipt_no', 'like', "%{$search}%")->orWhere('pr.supplier_invoice_no', 'like', "%{$search}%")->orWhere('po.order_no', 'like', "%{$search}%")->orWhere('s.name', 'like', "%{$search}%")->orWhere('w.name', 'like', "%{$search}%");
            });
        }
        $filtered = (clone $query)->count();
        $rows = $query->orderBy($columns[$orderIndex] ?? 'pr.id', $direction)->skip($start)->take($length)->get();
        $user = $request->user();
        $canEdit = $user?->canDo('purchase.receipts.update', 'edit') ?? false;
        $canDelete = $user?->canDo('purchase.receipts.destroy', 'delete') ?? false;
        $data = $rows->map(function (object $row) use ($canEdit, $canDelete) {
            $status = PurchaseReceiptStatus::from($row->status);
            $class = $status === PurchaseReceiptStatus::Posted ? 'bg-dark-success-gradient' : 'bg-dark-warning-gradient';
            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100"><a href="'.e(route('purchase.receipts.show', $row->id)).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main" title="View"><iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';
            if ($canEdit && $status === PurchaseReceiptStatus::Draft) {
                $actions .= '<a href="'.e(route('purchase.receipts.edit', $row->id)).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main" title="Edit"><iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }
            if ($canDelete && $status === PurchaseReceiptStatus::Draft) {
                $actions .= '<a href="#" data-url="'.e(route('purchase.receipts.destroy', $row->id)).'" class="btn-purchase-receipt-delete w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main" title="Delete"><iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
            }
            $actions .= '</div>';

            $invoice = $row->supplier_invoice_path
                ? '<a href="'.e(asset($row->supplier_invoice_path)).'" target="_blank" rel="noopener" class="text-sm">Invoice attached</a>'
                : '<small>No invoice attachment</small>';

            return [$row->id, '<a class="fw-semibold text-primary-600" href="'.e(route('purchase.receipts.show', $row->id)).'">'.e($row->receipt_no).'</a><br>'.$invoice, e(date('d M Y', strtotime($row->receipt_date))), e($row->supplier_name), '<strong>'.e($row->warehouse_name).'</strong><br><code>'.e($row->warehouse_code).'</code>', e($row->order_no ?: 'Direct receipt'), (int) $row->line_count, number_format((float) $row->total, 2), '<span class="badge text-sm fw-semibold '.$class.' px-12 py-7 radius-4 text-white">'.e($status->label()).'</span>', $actions];
        })->all();

        return response()->json(['draw' => $draw, 'iTotalRecords' => $total, 'iTotalDisplayRecords' => $filtered, 'aaData' => $data]);
    }

    public function create(Request $request): View
    {
        $order = null;
        if ($request->filled('purchase_order_id')) {
            $order = $this->eligibleOrder((int) $request->input('purchase_order_id'));
        }

        return $this->formView(null, $order);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $newInvoice = $this->storeSupplierInvoice($request->file('supplier_invoice_file'));
        $data['supplier_invoice_path'] = $newInvoice;
        try {
            $receipt = $this->purchaseReceipt->createDraft($data, $data['lines'], $request->user()?->id);
        } catch (InvalidPurchaseReceiptException $e) {
            $this->deleteSupplierInvoice($newInvoice);

            return back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            $this->deleteSupplierInvoice($newInvoice);

            throw $e;
        }

        return redirect()->route('purchase.receipts.show', $receipt)->with('success', 'Purchase receipt draft created successfully.');
    }

    public function show(int $purchaseReceipt): View
    {
        $receipt = $this->record($purchaseReceipt);

        return view('backend.modules.purchase_receipt.show', ['receipt' => $receipt, 'lines' => $this->documentLines($receipt)]);
    }

    public function edit(int $purchaseReceipt): View
    {
        $receipt = $this->record($purchaseReceipt);
        abort_unless($receipt->status === PurchaseReceiptStatus::Draft, 409, 'Only a draft purchase receipt can be edited.');

        return $this->formView($receipt, $receipt->order);
    }

    public function update(Request $request, int $purchaseReceipt): RedirectResponse
    {
        $receipt = $this->record($purchaseReceipt);
        $data = $this->validated($request);
        $oldInvoice = $receipt->supplier_invoice_path;
        $newInvoice = $this->storeSupplierInvoice($request->file('supplier_invoice_file'));
        $data['supplier_invoice_path'] = $newInvoice ?: $oldInvoice;
        try {
            $receipt = $this->purchaseReceipt->updateDraft($receipt, $data, $data['lines']);
        } catch (InvalidPurchaseReceiptException $e) {
            $this->deleteSupplierInvoice($newInvoice);

            return back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            $this->deleteSupplierInvoice($newInvoice);

            throw $e;
        }
        if ($newInvoice && $oldInvoice !== $newInvoice) {
            $this->deleteSupplierInvoice($oldInvoice);
        }

        return redirect()->route('purchase.receipts.show', $receipt)->with('success', 'Purchase receipt draft updated successfully.');
    }

    public function post(Request $request, int $purchaseReceipt): RedirectResponse
    {
        try {
            $this->purchaseReceipt->post($this->record($purchaseReceipt), $request->user()?->id);
        } catch (InvalidPurchaseReceiptException|FinanceConfigurationException|InvalidJournalDraftException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Purchase receipt posted. Stock, purchase-order progress and the finance draft were updated.');
    }

    public function destroy(int $purchaseReceipt): JsonResponse
    {
        $receipt = $this->record($purchaseReceipt);
        $invoice = $receipt->supplier_invoice_path;
        try {
            $this->purchaseReceipt->deleteDraft($receipt);
        } catch (InvalidPurchaseReceiptException $e) {
            return response()->json(['success' => false, 'msg' => $e->getMessage()], 422);
        }
        $this->deleteSupplierInvoice($invoice);

        return response()->json(['success' => true, 'msg' => 'Purchase receipt draft deleted successfully.']);
    }

    public function itemSelect2(Request $request): JsonResponse
    {
        $domain = (string) $request->input('domain');
        abort_unless(isset(self::DOMAINS[$domain]), 404);
        $c = self::DOMAINS[$domain];
        $supplierId = (int) $request->input('supplier_id');
        if ($supplierId < 1 || ! DB::table('suppliers')->where('id', $supplierId)->whereNull('deleted_at')->where('is_active', true)->exists()) {
            return response()->json(['results' => [], 'pagination' => ['more' => false]]);
        }
        $search = trim((string) $request->input('q', ''));
        $categoryId = max((int) $request->input('category_id'), 0);
        $page = max((int) $request->input('page', 1), 1);
        $perPage = 20;
        $q = DB::table($c['item_table'].' as i')
            ->leftJoin($c['supplier_table'].' as sm', function ($join) use ($c, $supplierId): void {
                $join->on('sm.'.$c['item_column'], '=', 'i.id')->where('sm.supplier_id', '=', $supplierId);
            })
            ->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->join($c['category_table'].' as cat', 'cat.id', '=', 'i.'.$c['category_column'])
            ->whereNull('i.deleted_at')->where('i.is_active', true)
            ->whereNull('cat.deleted_at')->where('cat.is_active', true);
        if ($domain === 'part') {
            $q->whereIn('i.procurement_mode', ['BUY', 'BOTH']);
        }
        if ($categoryId > 0) {
            $q->where('i.'.$c['category_column'], $categoryId);
        }
        if ($search !== '') {
            $q->where(fn (Builder $b) => $b->where('i.name', 'like', "%{$search}%")
                ->orWhere('i.'.$c['code_column'], 'like', "%{$search}%")
                ->orWhere('sm.supplier_code', 'like', "%{$search}%"));
        }
        $total = (clone $q)->count();
        $results = $q->orderByRaw('CASE WHEN sm.id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('sm.is_preferred')->orderBy('i.name')
            ->skip(($page - 1) * $perPage)->take($perPage)
            ->get([
                'i.id', 'i.name', 'i.'.$c['code_column'].' as code', 'i.image_path',
                'u.id as base_unit_id', 'u.code as unit_code', 'u.precision as unit_precision',
                'cat.name as category_name', 'sm.id as supplier_mapping_id', 'sm.supplier_code', 'sm.is_preferred',
            ])->map(fn ($item): array => [
                'id' => (int) $item->id,
                'domain' => $domain,
                'domain_label' => $c['label'],
                'name' => $item->name,
                'code' => $item->code,
                'image_url' => $item->image_path ? image($item->image_path) : asset('theme/admin/assets/images/datatable/purchase1.svg'),
                'base_unit_id' => (int) $item->base_unit_id,
                'unit_code' => $item->unit_code,
                'unit_precision' => (int) $item->unit_precision,
                'category_name' => $item->category_name,
                'supplier_code' => $item->supplier_code,
                'is_mapped' => $item->supplier_mapping_id !== null,
                'is_preferred' => (bool) $item->is_preferred,
            ]);

        return response()->json(['results' => $results, 'pagination' => ['more' => $page * $perPage < $total]]);
    }

    public function unitSelect2(Request $request): JsonResponse
    {
        $domain = (string) $request->input('domain');
        abort_unless(isset(self::DOMAINS[$domain]), 404);
        $c = self::DOMAINS[$domain];
        $itemId = (int) $request->input('item_id');
        $item = DB::table($c['item_table'].' as i')->join('units as u', 'u.id', '=', 'i.base_unit_id')->where('i.id', $itemId)->whereNull('i.deleted_at')->where('i.is_active', true)->first(['u.id', 'u.name', 'u.code', 'u.precision']);
        if (! $item) {
            return response()->json(['results' => []]);
        }
        $results = collect([['id' => $item->id, 'text' => $item->name.' ('.$item->code.') — Base unit', 'precision' => (int) $item->precision]])->concat(DB::table($c['unit_table'].' as m')->join('units as u', 'u.id', '=', 'm.unit_id')->where('m.'.$c['item_column'], $itemId)->whereIn('m.usage_type', ['PURCHASE', 'ALL'])->whereNull('u.deleted_at')->where('u.is_active', true)->orderBy('u.name')->get(['u.id', 'u.name', 'u.code', 'u.precision', 'm.conversion_to_base'])->map(fn ($u) => ['id' => $u->id, 'text' => $u->name.' ('.$u->code.') — 1 '.$u->code.' = '.$this->formatQuantity($u->conversion_to_base).' base', 'precision' => (int) $u->precision]))->values();

        return response()->json(['results' => $results]);
    }

    private function formView(?PurchaseReceipt $receipt, ?PurchaseOrder $order): View
    {
        if ($order) {
            $order = $this->eligibleOrder((int) $order->id);
        }
        $suppliers = DB::table('suppliers')->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']);
        $warehouses = DB::table('warehouses as w')->join('branches as b', 'b.id', '=', 'w.branch_id')->whereNull('w.deleted_at')->whereNull('b.deleted_at')->where('w.is_active', true)->where('b.is_active', true)->when(current_branch_id(), fn (Builder $q, int $id) => $q->where('w.branch_id', $id))->orderBy('b.name')->orderBy('w.name')->get(['w.id', 'w.name', 'w.code', 'b.name as branch_name']);
        $orders = PurchaseOrder::query()->with(['supplier', 'warehouse'])->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Partial])->when(current_branch_id(), fn ($q, int $id) => $q->where('branch_id', $id))->orderByDesc('order_date')->get();
        $lines = $receipt ? $this->documentLines($receipt) : ($order ? $this->pendingOrderLines($order) : []);
        $catalogCategories = [
            'raw_material' => DB::table('raw_material_categories')->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'part' => DB::table('part_categories')->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
        $poCatalogItems = $order ? $this->pendingOrderLines($order) : [];

        return view('backend.modules.purchase_receipt.form', compact('receipt', 'order', 'lines', 'suppliers', 'warehouses', 'orders', 'catalogCategories', 'poCatalogItems'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_order,id'],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($q) => $q->whereNull('deleted_at')->where('is_active', true))],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(function ($q) {
                $q->whereNull('deleted_at')->where('is_active', true);
                if ($id = current_branch_id()) {
                    $q->where('branch_id', $id);
                }
            })],
            'receipt_date' => ['required', 'date'], 'supplier_invoice_no' => ['nullable', 'string', 'max:80'],
            'supplier_invoice_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'discount_type' => ['required', Rule::in(['FLAT', 'PERCENT'])],
            'discount_value' => ['required', 'numeric', 'min:0', Rule::when($request->input('discount_type') === 'PERCENT', ['max:100'], ['max:9999999999999999.9999'])],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.9999'], 'note' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.purchase_order_item_id' => ['nullable', 'integer', 'exists:purchase_order_item,id'],
            'lines.*.domain' => ['required', Rule::in(array_keys(self::DOMAINS))], 'lines.*.item_id' => ['required', 'integer', 'min:1'], 'lines.*.unit_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.999999'], 'lines.*.unit_rate' => ['required', 'numeric', 'gt:0', 'max:9999999999999999.9999'],
        ]);
    }

    private function eligibleOrder(int $id): PurchaseOrder
    {
        $order = PurchaseOrder::query()->whereKey($id)->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Partial])->when(current_branch_id(), fn ($q, int $branchId) => $q->where('branch_id', $branchId))->with(['supplier', 'warehouse', 'items.rawMaterial.baseUnit', 'items.rawMaterial.category', 'items.part.baseUnit', 'items.part.category', 'items.unit'])->first();
        abort_unless($order, 404);

        return $order;
    }

    private function record(int $id): PurchaseReceipt
    {
        $r = PurchaseReceipt::query()->whereKey($id)->when(current_branch_id(), fn ($q, int $branchId) => $q->where('branch_id', $branchId))->with(['order', 'branch', 'supplier', 'warehouse', 'createdBy', 'postedBy', 'items.rawMaterial.baseUnit', 'items.part.baseUnit', 'items.unit'])->first();
        abort_unless($r, 404);

        return $r;
    }

    private function pendingOrderLines(PurchaseOrder $order): array
    {
        return $order->items->filter(fn ($l) => (float) $l->received_base_quantity < (float) $l->base_quantity)->map(function ($l) {
            $domain = $l->raw_material_id ? 'raw_material' : 'part';
            $c = self::DOMAINS[$domain];
            $item = $l->raw_material_id ? $l->rawMaterial : $l->part;
            $pending = (float) $l->base_quantity - (float) $l->received_base_quantity;
            $qty = $pending / (float) $l->conversion_to_base;

            return [
                'purchase_order_item_id' => (int) $l->id,
                'domain' => $domain,
                'domain_label' => $c['label'],
                'item_id' => (int) $item->id,
                'item_text' => $item->name.' ('.$item->{$c['code_column']}.')',
                'name' => $item->name,
                'code' => $item->{$c['code_column']},
                'image_url' => $item->image_path ? image($item->image_path) : asset('theme/admin/assets/images/datatable/purchase1.svg'),
                'category_id' => (int) $item->{$c['category_column']},
                'category_name' => $item->category?->name ?? 'Uncategorized',
                'unit_id' => (int) $l->unit_id,
                'unit_text' => $l->unit->name.' ('.$l->unit->code.')',
                'unit_code' => $l->unit->code,
                'unit_precision' => (int) $l->unit->precision,
                'quantity_input' => $this->formatQuantityForUnit($qty, (int) $l->unit->precision),
                'unit_rate' => $l->unit_rate,
                'ordered_base_quantity' => (float) $l->base_quantity,
                'received_base_quantity' => (float) $l->received_base_quantity,
                'pending_base_quantity' => $pending,
                'base_unit_code' => $item->baseUnit->code,
            ];
        })->values()->all();
    }

    private function documentLines(PurchaseReceipt $receipt): array
    {
        return $receipt->items->map(function ($l) {
            $domain = $l->raw_material_id ? 'raw_material' : 'part';
            $c = self::DOMAINS[$domain];
            $item = $l->raw_material_id ? $l->rawMaterial : $l->part;

            return ['id' => $l->id, 'purchase_order_item_id' => $l->purchase_order_item_id, 'domain' => $domain, 'domain_label' => $c['label'], 'item_id' => $item->id, 'item_text' => $item->name.' ('.$item->{$c['code_column']}.')', 'unit_id' => $l->unit_id, 'unit_text' => $l->unit->name.' ('.$l->unit->code.')', 'unit_precision' => (int) $l->unit->precision, 'quantity_input' => $this->formatQuantityForUnit($l->quantity, (int) $l->unit->precision), 'quantity' => $l->quantity, 'quantity_display' => $this->formatQuantity($l->quantity), 'base_quantity' => $l->base_quantity, 'base_quantity_display' => $this->formatQuantity($l->base_quantity), 'base_unit_code' => $item->baseUnit->code, 'unit_rate' => $l->unit_rate, 'line_total' => $l->line_total];
        })->all();
    }

    private function formatQuantity(string|float|int $q): string
    {
        return rtrim(rtrim(number_format((float) $q, 6, '.', ''), '0'), '.');
    }

    private function formatQuantityForUnit(string|float|int $q, int $precision): string
    {
        $precision = max(0, min(6, $precision));

        return $precision === 0 ? (string) (int) round((float) $q) : number_format((float) $q, $precision, '.', '');
    }

    private function storeSupplierInvoice(?UploadedFile $file): ?string
    {
        if (! $file) {
            return null;
        }

        $relativeDirectory = 'uploads/purchase-receipts/invoices/'.now()->format('Y/m/d');
        File::ensureDirectoryExists(base_path('../'.$relativeDirectory), 0755, true);
        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
        $filename = Str::uuid()->toString().'.'.$extension;
        $file->move(base_path('../'.$relativeDirectory), $filename);

        return $relativeDirectory.'/'.$filename;
    }

    private function deleteSupplierInvoice(?string $path): void
    {
        $path = ltrim(str_replace('\\', '/', (string) $path), '/');
        if ($path === '' || ! str_starts_with($path, 'uploads/purchase-receipts/invoices/')) {
            return;
        }

        File::delete(base_path('../'.$path));
    }
}
