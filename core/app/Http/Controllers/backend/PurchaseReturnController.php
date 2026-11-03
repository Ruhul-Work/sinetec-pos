<?php

namespace App\Http\Controllers\backend;

use App\Enums\Purchase\PurchaseReceiptStatus;
use App\Enums\Purchase\PurchaseReturnStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Purchase\InvalidPurchaseReturnException;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseReceipt;
use App\Models\Purchase\PurchaseReceiptItem;
use App\Models\Purchase\PurchaseReturn;
use App\Services\Purchase\PurchaseReturnService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseReturnController extends Controller
{
    private const DOMAINS = [
        'raw_material' => ['label' => 'Raw Material', 'code_column' => 'material_code'],
        'part' => ['label' => 'Part', 'code_column' => 'part_code'],
    ];

    public function __construct(private readonly PurchaseReturnService $purchaseReturn) {}

    public function index(): View
    {
        return view('backend.modules.purchase_return.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['pr.id', 'pr.return_no', 'pr.return_date', 's.name', 'w.name', 'rc.receipt_no', 'line_count', 'pr.total', 'pr.status', 'pr.id'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $direction = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));
        $status = strtoupper(trim((string) $request->input('status', '')));
        $counts = DB::table('purchase_return_item')->select('purchase_return_id', DB::raw('COUNT(*) as line_count'))->groupBy('purchase_return_id');
        $query = DB::table('purchase_return as pr')
            ->join('purchase_receipt as rc', 'rc.id', '=', 'pr.purchase_receipt_id')
            ->join('suppliers as s', 's.id', '=', 'pr.supplier_id')
            ->join('warehouses as w', 'w.id', '=', 'pr.warehouse_id')
            ->join('branches as b', 'b.id', '=', 'pr.branch_id')
            ->leftJoinSub($counts, 'lc', 'lc.purchase_return_id', '=', 'pr.id')
            ->when(current_branch_id(), fn (Builder $q, int $branchId) => $q->where('pr.branch_id', $branchId))
            ->select([
                'pr.id', 'pr.return_no', 'pr.return_date', 'pr.status', 'pr.total',
                's.name as supplier_name', 'w.name as warehouse_name', 'w.code as warehouse_code',
                'b.name as branch_name', 'rc.id as receipt_id', 'rc.receipt_no', DB::raw('COALESCE(lc.line_count, 0) as line_count'),
            ]);
        $total = (clone $query)->count();
        if (PurchaseReturnStatus::tryFrom($status)) {
            $query->where('pr.status', $status);
        }
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('pr.return_no', 'like', "%{$search}%")
                    ->orWhere('rc.receipt_no', 'like', "%{$search}%")
                    ->orWhere('s.name', 'like', "%{$search}%")
                    ->orWhere('w.name', 'like', "%{$search}%");
            });
        }
        $filtered = (clone $query)->count();
        $rows = $query->orderBy($columns[$orderIndex] ?? 'pr.id', $direction)->skip($start)->take($length)->get();
        $user = $request->user();
        $canEdit = $user?->canDo('purchase.returns.update', 'edit') ?? false;
        $canDelete = $user?->canDo('purchase.returns.destroy', 'delete') ?? false;
        $data = $rows->map(function (object $row) use ($canEdit, $canDelete): array {
            $status = PurchaseReturnStatus::from($row->status);
            $class = $status === PurchaseReturnStatus::Posted ? 'bg-dark-success-gradient' : 'bg-dark-warning-gradient';
            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100"><a href="'.e(route('purchase.returns.show', $row->id)).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main" title="View"><iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';
            if ($canEdit && $status === PurchaseReturnStatus::Draft) {
                $actions .= '<a href="'.e(route('purchase.returns.edit', $row->id)).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main" title="Edit"><iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }
            if ($canDelete && $status === PurchaseReturnStatus::Draft) {
                $actions .= '<a href="#" data-url="'.e(route('purchase.returns.destroy', $row->id)).'" class="btn-purchase-return-delete w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main" title="Delete"><iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
            }
            $actions .= '</div>';

            return [
                $row->id,
                '<a class="fw-semibold text-primary-600" href="'.e(route('purchase.returns.show', $row->id)).'">'.e($row->return_no).'</a>',
                e(date('d M Y', strtotime($row->return_date))),
                e($row->supplier_name),
                '<strong>'.e($row->warehouse_name).'</strong><br><code>'.e($row->warehouse_code).'</code>',
                '<a href="'.e(route('purchase.receipts.show', $row->receipt_id)).'" class="fw-semibold">'.e($row->receipt_no).'</a>',
                (int) $row->line_count,
                number_format((float) $row->total, 2),
                '<span class="badge text-sm fw-semibold '.$class.' px-12 py-7 radius-4 text-white">'.e($status->label()).'</span>',
                $actions,
            ];
        })->all();

        return response()->json(['draw' => $draw, 'iTotalRecords' => $total, 'iTotalDisplayRecords' => $filtered, 'aaData' => $data]);
    }

    public function create(Request $request): View
    {
        $receipt = $request->filled('purchase_receipt_id')
            ? $this->eligibleReceipt((int) $request->input('purchase_receipt_id'))
            : null;

        return $this->formView(null, $receipt);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        try {
            $return = $this->purchaseReturn->createDraft($data, $data['lines'], $request->user()?->id);
        } catch (InvalidPurchaseReturnException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('purchase.returns.show', $return)->with('success', 'Purchase return draft created successfully.');
    }

    public function show(int $purchaseReturn): View
    {
        $return = $this->record($purchaseReturn);

        return view('backend.modules.purchase_return.show', ['return' => $return, 'lines' => $this->documentLines($return)]);
    }

    public function edit(int $purchaseReturn): View
    {
        $return = $this->record($purchaseReturn);
        abort_unless($return->status === PurchaseReturnStatus::Draft, 409, 'Only a draft purchase return can be edited.');

        return $this->formView($return, $return->receipt);
    }

    public function update(Request $request, int $purchaseReturn): RedirectResponse
    {
        $return = $this->record($purchaseReturn);
        $data = $this->validated($request);
        try {
            $return = $this->purchaseReturn->updateDraft($return, $data, $data['lines']);
        } catch (InvalidPurchaseReturnException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('purchase.returns.show', $return)->with('success', 'Purchase return draft updated successfully.');
    }

    public function post(Request $request, int $purchaseReturn): RedirectResponse
    {
        try {
            $this->purchaseReturn->post($this->record($purchaseReturn), $request->user()?->id);
        } catch (InvalidPurchaseReturnException|InsufficientStockException|FinanceConfigurationException|InvalidJournalDraftException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Purchase return posted. Warehouse stock was reduced and the finance draft was created.');
    }

    public function destroy(int $purchaseReturn): JsonResponse
    {
        try {
            $this->purchaseReturn->deleteDraft($this->record($purchaseReturn));
        } catch (InvalidPurchaseReturnException $e) {
            return response()->json(['success' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'msg' => 'Purchase return draft deleted successfully.']);
    }

    private function formView(?PurchaseReturn $return, ?PurchaseReceipt $receipt): View
    {
        if ($receipt) {
            $receipt = $this->eligibleReceipt((int) $receipt->id);
        }
        $receipts = PurchaseReceipt::query()
            ->with(['supplier:id,name,code', 'warehouse:id,name,code', 'items'])
            ->where('status', PurchaseReceiptStatus::Posted)
            ->when(current_branch_id(), fn ($q, int $branchId) => $q->where('branch_id', $branchId))
            ->orderByDesc('receipt_date')->limit(200)->get()
            ->filter(fn (PurchaseReceipt $candidate) => $candidate->items->contains(fn (PurchaseReceiptItem $item) => (float) $this->purchaseReturn->availableBaseQuantity($item) > 0))
            ->values();
        if ($receipt && ! $receipts->contains('id', $receipt->id)) {
            $receipts->prepend($receipt);
        }
        $lines = $return ? $this->documentLines($return) : [];
        $catalogItems = $receipt ? $this->availableReceiptLines($receipt) : [];

        return view('backend.modules.purchase_return.form', compact('return', 'receipt', 'receipts', 'lines', 'catalogItems'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'purchase_receipt_id' => ['required', 'integer', Rule::exists('purchase_receipt', 'id')->where(fn ($q) => $q->where('status', PurchaseReceiptStatus::Posted->value))],
            'return_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_receipt_item_id' => ['required', 'integer', 'exists:purchase_receipt_item,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.999999'],
        ]);
    }

    private function eligibleReceipt(int $id): PurchaseReceipt
    {
        $receipt = PurchaseReceipt::query()
            ->whereKey($id)->where('status', PurchaseReceiptStatus::Posted)
            ->when(current_branch_id(), fn ($q, int $branchId) => $q->where('branch_id', $branchId))
            ->with(['supplier', 'warehouse', 'items.rawMaterial.baseUnit', 'items.part.baseUnit', 'items.unit'])
            ->first();
        abort_unless($receipt, 404);

        return $receipt;
    }

    private function record(int $id): PurchaseReturn
    {
        $return = PurchaseReturn::query()
            ->whereKey($id)
            ->when(current_branch_id(), fn ($q, int $branchId) => $q->where('branch_id', $branchId))
            ->with([
                'receipt', 'branch', 'supplier', 'warehouse', 'createdBy', 'postedBy',
                'items.receiptItem', 'items.rawMaterial.baseUnit', 'items.part.baseUnit', 'items.unit',
            ])->first();
        abort_unless($return, 404);

        return $return;
    }

    /** @return array<int, array<string, mixed>> */
    private function availableReceiptLines(PurchaseReceipt $receipt): array
    {
        return $receipt->items->map(function (PurchaseReceiptItem $source): ?array {
            $domain = $source->raw_material_id ? 'raw_material' : 'part';
            $item = $source->raw_material_id ? $source->rawMaterial : $source->part;
            $availableBase = (float) $this->purchaseReturn->availableBaseQuantity($source);
            if ($availableBase <= 0) {
                return null;
            }
            $factor = (float) $source->conversion_to_base;
            $availableQuantity = $availableBase / $factor;
            $returnedQuantity = ((float) $source->base_quantity - $availableBase) / $factor;

            return [
                'purchase_receipt_item_id' => (int) $source->id,
                'domain' => $domain,
                'domain_label' => self::DOMAINS[$domain]['label'],
                'item_id' => (int) $item->id,
                'name' => $item->name,
                'code' => $item->{self::DOMAINS[$domain]['code_column']},
                'image_url' => $item->image_path ? image($item->image_path) : asset('theme/admin/assets/images/datatable/purchase1.svg'),
                'unit_id' => (int) $source->unit_id,
                'unit_text' => $source->unit->name.' ('.$source->unit->code.')',
                'unit_code' => $source->unit->code,
                'unit_precision' => (int) $source->unit->precision,
                'received_quantity' => $this->formatQuantityForUnit($source->quantity, (int) $source->unit->precision),
                'returned_quantity' => $this->formatQuantityForUnit($returnedQuantity, (int) $source->unit->precision),
                'available_quantity' => $this->formatQuantityForUnit($availableQuantity, (int) $source->unit->precision),
                'available_base_quantity' => $availableBase,
                'base_unit_code' => $item->baseUnit->code,
                'unit_rate' => $source->unit_rate,
            ];
        })->filter()->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function documentLines(PurchaseReturn $return): array
    {
        return $return->items->map(function ($line): array {
            $domain = $line->raw_material_id ? 'raw_material' : 'part';
            $item = $line->raw_material_id ? $line->rawMaterial : $line->part;
            $availableBase = (float) $this->purchaseReturn->availableBaseQuantity($line->receiptItem);
            $maxQuantity = $availableBase / (float) $line->conversion_to_base;

            return [
                'id' => $line->id,
                'purchase_receipt_item_id' => (int) $line->purchase_receipt_item_id,
                'domain' => $domain,
                'domain_label' => self::DOMAINS[$domain]['label'],
                'item_id' => (int) $item->id,
                'item_text' => $item->name.' ('.$item->{self::DOMAINS[$domain]['code_column']}.')',
                'unit_text' => $line->unit->name.' ('.$line->unit->code.')',
                'unit_code' => $line->unit->code,
                'unit_precision' => (int) $line->unit->precision,
                'quantity_input' => $this->formatQuantityForUnit($line->quantity, (int) $line->unit->precision),
                'max_quantity' => $this->formatQuantityForUnit($maxQuantity, (int) $line->unit->precision),
                'base_quantity_display' => $this->formatQuantity($line->base_quantity),
                'base_unit_code' => $item->baseUnit->code,
                'unit_rate' => $line->unit_rate,
                'line_total' => $line->line_total,
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

        return $precision === 0 ? (string) (int) round((float) $quantity) : number_format((float) $quantity, $precision, '.', '');
    }
}
