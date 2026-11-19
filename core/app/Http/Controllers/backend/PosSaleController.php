<?php

namespace App\Http\Controllers\backend;

use App\Enums\Sales\SaleChannel;
use App\Enums\Sales\SaleStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Exceptions\Finance\JournalWorkflowException;
use App\Exceptions\Inventory\StockPostingException;
use App\Exceptions\Sales\InvalidSaleCheckoutException;
use App\Exceptions\Sales\InvalidSaleException;
use App\Http\Controllers\Controller;
use App\Models\backend\JournalEntry;
use App\Models\Sales\Sale;
use App\Services\Sales\SaleCheckoutService;
use App\Services\Sales\SaleDraftService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PosSaleController extends Controller
{
    public function __construct(
        private readonly SaleDraftService $saleDraftService,
        private readonly SaleCheckoutService $saleCheckoutService,
    ) {}

    public function index(): View
    {
        return view('backend.modules.pos_sale.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $status = strtoupper(trim((string) $request->input('status', '')));
        $search = trim((string) $request->input('search.value', ''));
        $lineCounts = DB::table('sale_items')
            ->selectRaw('sale_id, COUNT(*) as line_count')
            ->groupBy('sale_id');

        $query = DB::table('sales as sale')
            ->join('branches as branch', 'branch.id', '=', 'sale.branch_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'sale.warehouse_id')
            ->leftJoin('customers as customer', 'customer.id', '=', 'sale.customer_id')
            ->leftJoinSub($lineCounts, 'line_count', function ($join): void {
                $join->on('line_count.sale_id', '=', 'sale.id');
            })
            ->where('sale.channel', SaleChannel::Pos->value)
            ->when(
                current_branch_id(),
                fn (Builder $builder, int $branchId) => $builder->where('sale.branch_id', $branchId),
            )
            ->select([
                'sale.id',
                'sale.sale_no',
                'sale.sale_at',
                'sale.total',
                'sale.paid_amount',
                'sale.due_amount',
                'sale.status',
                'branch.name as branch_name',
                'warehouse.name as warehouse_name',
                'customer.name as customer_name',
                DB::raw('COALESCE(line_count.line_count, 0) as line_count'),
            ]);

        $total = (clone $query)->count();
        if (in_array($status, array_column(SaleStatus::cases(), 'value'), true)) {
            $query->where('sale.status', $status);
        }
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('sale.sale_no', 'like', "%{$search}%")
                    ->orWhere('customer.name', 'like', "%{$search}%")
                    ->orWhere('warehouse.name', 'like', "%{$search}%")
                    ->orWhere('branch.name', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $requestedLength = (int) $request->input('length', 10);
        $length = $requestedLength === -1 ? 1000 : min(max($requestedLength, 1), 1000);
        $rows = $query
            ->orderByDesc('sale.id')
            ->skip(max((int) $request->input('start', 0), 0))
            ->take($length)
            ->get();
        $user = $request->user();

        $data = $rows->map(function (object $row) use ($user): array {
            $saleStatus = SaleStatus::from($row->status);
            $actionClass = 'w-32-px h-32-px rounded-circle d-inline-flex '
                .'align-items-center justify-content-center';
            $actions = '<div class="d-inline-flex justify-content-end align-items-center gap-1 w-100">';

            if ($user?->canDo('sales.pos.show', 'show')) {
                $actions .= '<a href="'.e(route('sales.pos.show', $row->id)).'" '
                    .'class="'.$actionClass.' bg-info-focus text-info-main" title="View">'
                    .'<iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';
            }
            if ($saleStatus === SaleStatus::Draft && $user?->canDo('sales.pos.edit', 'edit')) {
                $actions .= '<a href="'.e(route('sales.pos.edit', $row->id)).'" '
                    .'class="'.$actionClass.' bg-success-focus text-success-main" title="Edit">'
                    .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }
            if ($saleStatus === SaleStatus::Draft && $user?->canDo('sales.pos.destroy', 'delete')) {
                $actions .= '<button type="button" data-url="'.e(route('sales.pos.destroy', $row->id)).'" '
                    .'class="btn '.$actionClass.' bg-danger-focus text-danger-main btn-pos-sale-delete" '
                    .'title="Delete">'
                    .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></button>';
            }
            if ($saleStatus === SaleStatus::Posted && $user?->canDo('sales.pos.receipt', 'view')) {
                $actions .= '<a href="'.e(route('sales.pos.receipt', $row->id)).'" target="_blank" '
                    .'class="'.$actionClass.' bg-success-focus text-success-main" title="Print Receipt">'
                    .'<iconify-icon icon="mdi:printer-outline"></iconify-icon></a>';
            }

            return [
                $row->id,
                '<a class="fw-semibold text-primary-600" href="'.e(route('sales.pos.show', $row->id)).'">'.e($row->sale_no).'</a>',
                e(Carbon::parse($row->sale_at)->format('d M Y, h:i A')),
                e($row->customer_name ?: 'Walk-in Customer'),
                e($row->warehouse_name).'<br><small>'.e($row->branch_name).'</small>',
                '<span class="d-block text-center">'.(int) $row->line_count.'</span>',
                '<span class="d-block text-end fw-semibold">'.number_format((float) $row->total, 2).'</span>',
                $this->statusBadge($saleStatus),
                $this->paymentStatusBadge(
                    $saleStatus,
                    (string) $row->paid_amount,
                    (string) $row->due_amount,
                ),
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
        return $this->formView(null);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $sale = $this->saleDraftService->createDraft(
                $this->header($data),
                $data['items'],
                $request->user()?->id,
            );
        } catch (InvalidSaleException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        if ($request->boolean('review')) {
            try {
                $sale = $this->saleCheckoutService->prepare(
                    $sale,
                    $data['payments'] ?? [],
                    (int) $request->user()?->id,
                );
            } catch (
                InvalidSaleCheckoutException|
                FinanceConfigurationException|
                InvalidJournalDraftException|
                JournalWorkflowException|
                StockPostingException $exception
            ) {
                return redirect()
                    ->route('sales.pos.edit', $sale)
                    ->withInput()
                    ->with('error', $exception->getMessage());
            }

            return redirect()
                ->route('sales.pos.show', $sale)
                ->with('success', 'POS Sale is ready for review. Stock and Finance have not been posted.');
        }

        return redirect()
            ->route('sales.pos.show', $sale)
            ->with('success', 'POS Sale Draft saved. Stock and Finance were not changed.');
    }

    public function show(int $posSale): View
    {
        $sale = $this->record($posSale);

        return view('backend.modules.pos_sale.show', [
            'sale' => $sale,
            'journal' => JournalEntry::query()
                ->with('voucherType')
                ->where('source_type', 'sale')
                ->where('source_id', $sale->id)
                ->first(),
        ]);
    }

    public function edit(int $posSale): View
    {
        $sale = $this->record($posSale);
        abort_unless($sale->status === SaleStatus::Draft, 409);

        return $this->formView($sale);
    }

    public function update(Request $request, int $posSale): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $sale = $this->saleDraftService->updateDraft(
                $this->record($posSale),
                $this->header($data),
                $data['items'],
            );
        } catch (InvalidSaleException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        if ($request->boolean('review')) {
            try {
                $sale = $this->saleCheckoutService->prepare(
                    $sale,
                    $data['payments'] ?? [],
                    (int) $request->user()?->id,
                );
            } catch (
                InvalidSaleCheckoutException|
                FinanceConfigurationException|
                InvalidJournalDraftException|
                JournalWorkflowException|
                StockPostingException $exception
            ) {
                return back()->withInput()->with('error', $exception->getMessage());
            }

            return redirect()
                ->route('sales.pos.show', $sale)
                ->with('success', 'POS Sale is ready for review. Stock and Finance have not been posted.');
        }

        return redirect()
            ->route('sales.pos.show', $sale)
            ->with('success', 'POS Sale Draft updated. Stock and Finance were not changed.');
    }

    public function destroy(int $posSale): JsonResponse
    {
        try {
            $this->saleDraftService->deleteDraft($this->record($posSale));
        } catch (InvalidSaleException $exception) {
            return response()->json([
                'success' => false,
                'msg' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'msg' => 'POS Sale Draft deleted.',
        ]);
    }

    public function reopen(int $posSale): RedirectResponse
    {
        try {
            $sale = $this->saleCheckoutService->reopen($this->record($posSale));
        } catch (InvalidSaleCheckoutException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('sales.pos.edit', $sale)
            ->with('success', 'Sale returned to Draft. Review payments were cleared.');
    }

    public function post(int $posSale, Request $request): RedirectResponse
    {
        try {
            $sale = $this->saleCheckoutService->post(
                $this->record($posSale),
                (int) $request->user()?->id,
            );
        } catch (
            InvalidSaleCheckoutException|
            FinanceConfigurationException|
            InvalidJournalDraftException|
            JournalWorkflowException|
            StockPostingException $exception
        ) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('sales.pos.receipt', $sale)
            ->with('success', 'Sale posted successfully.');
    }

    public function receipt(int $posSale): View
    {
        $sale = $this->record($posSale);
        abort_unless($sale->status === SaleStatus::Posted, 409);

        return view('backend.modules.pos_sale.receipt', ['sale' => $sale]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $warehouse = $this->availableWarehouse((int) $request->input('warehouse_id'));
        $search = trim((string) $request->input('q', ''));
        $barcode = trim((string) $request->input('barcode', ''));
        $categoryId = max((int) $request->input('category_id'), 0);
        $page = max((int) $request->input('page', 1), 1);
        $perPage = 24;

        $query = DB::table('finished_products as product')
            ->join('units as unit', 'unit.id', '=', 'product.base_unit_id')
            ->join(
                'finished_product_categories as category',
                'category.id',
                '=',
                'product.finished_product_category_id',
            )
            ->leftJoin('finished_product_stock_balances as stock', function ($join) use ($warehouse): void {
                $join->on('stock.finished_product_id', '=', 'product.id')
                    ->where('stock.warehouse_id', '=', $warehouse->id);
            })
            ->whereNull('product.deleted_at')
            ->where('product.is_active', true)
            ->whereNull('category.deleted_at')
            ->where('category.is_active', true);

        if ($barcode !== '') {
            $query->where('product.barcode', $barcode);
        } else {
            if ($categoryId > 0) {
                $query->where('product.finished_product_category_id', $categoryId);
            }
            if ($search !== '') {
                $query->where(function (Builder $builder) use ($search): void {
                    $builder->where('product.name', 'like', "%{$search}%")
                        ->orWhere('product.product_code', 'like', "%{$search}%")
                        ->orWhere('product.barcode', 'like', "%{$search}%");
                });
            }
        }

        $total = (clone $query)->count();
        $products = $query
            ->orderBy('product.name')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get([
                'product.id',
                'product.name',
                'product.product_code',
                'product.image_path',
                'product.retail_price',
                'product.base_unit_id',
                'unit.name as unit_name',
                'unit.code as unit_code',
                'unit.precision as unit_precision',
                'category.name as category_name',
                DB::raw('COALESCE(stock.quantity, 0) as stock_quantity'),
            ])
            ->map(fn (object $product): array => [
                'id' => (int) $product->id,
                'name' => $product->name,
                'code' => $product->product_code,
                'category_name' => $product->category_name,
                'image_url' => $product->image_path
                    ? image($product->image_path)
                    : asset('theme/admin/assets/images/datatable/purchase1.svg'),
                'base_unit_id' => (int) $product->base_unit_id,
                'base_unit_name' => $product->unit_name,
                'base_unit_code' => $product->unit_code,
                'unit_precision' => (int) $product->unit_precision,
                'retail_price' => $product->retail_price === null
                    ? null
                    : number_format((float) $product->retail_price, 2, '.', ''),
                'stock_quantity' => $this->formatQuantity($product->stock_quantity),
                'in_stock' => bccomp((string) $product->stock_quantity, '0', 6) === 1,
            ]);

        return response()->json([
            'results' => $products,
            'pagination' => [
                'more' => $page * $perPage < $total,
                'total' => $total,
            ],
        ]);
    }

    public function units(Request $request, int $finishedProduct): JsonResponse
    {
        $product = DB::table('finished_products as product')
            ->join('units as unit', 'unit.id', '=', 'product.base_unit_id')
            ->where('product.id', $finishedProduct)
            ->whereNull('product.deleted_at')
            ->where('product.is_active', true)
            ->whereNull('unit.deleted_at')
            ->where('unit.is_active', true)
            ->first([
                'product.id',
                'product.retail_price',
                'unit.id as unit_id',
                'unit.name as unit_name',
                'unit.code as unit_code',
                'unit.precision',
            ]);
        abort_unless($product, 404);

        $basePrice = $product->retail_price === null ? null : (string) $product->retail_price;
        $results = collect([[
            'id' => (int) $product->unit_id,
            'text' => $product->unit_name.' ('.$product->unit_code.') — Base unit',
            'precision' => (int) $product->precision,
            'conversion_to_base' => '1.000000',
            'default_price' => $basePrice === null ? null : number_format((float) $basePrice, 2, '.', ''),
        ]])->concat(
            DB::table('finished_product_units as mapping')
                ->join('units as unit', 'unit.id', '=', 'mapping.unit_id')
                ->where('mapping.finished_product_id', $finishedProduct)
                ->whereIn('mapping.usage_type', ['SALE', 'ALL'])
                ->whereNull('unit.deleted_at')
                ->where('unit.is_active', true)
                ->orderBy('unit.name')
                ->get([
                    'unit.id',
                    'unit.name',
                    'unit.code',
                    'unit.precision',
                    'mapping.conversion_to_base',
                ])
                ->map(fn (object $unit): array => [
                    'id' => (int) $unit->id,
                    'text' => $unit->name.' ('.$unit->code.') — 1 '.$unit->code.' = '
                        .$this->formatQuantity($unit->conversion_to_base).' base',
                    'precision' => (int) $unit->precision,
                    'conversion_to_base' => (string) $unit->conversion_to_base,
                    'default_price' => $basePrice === null
                        ? null
                        : number_format((float) $basePrice * (float) $unit->conversion_to_base, 2, '.', ''),
                ]),
        )->values();

        return response()->json(['results' => $results]);
    }

    private function formView(?Sale $sale): View
    {
        $warehouses = DB::table('warehouses as warehouse')
            ->join('branches as branch', 'branch.id', '=', 'warehouse.branch_id')
            ->whereNull('warehouse.deleted_at')
            ->whereNull('branch.deleted_at')
            ->where('warehouse.is_active', true)
            ->where('branch.is_active', true)
            ->when(
                current_branch_id(),
                fn (Builder $query, int $branchId) => $query->where('warehouse.branch_id', $branchId),
            )
            ->orderBy('branch.name')
            ->orderBy('warehouse.name')
            ->get([
                'warehouse.id',
                'warehouse.branch_id',
                'warehouse.name',
                'warehouse.code',
                'branch.name as branch_name',
            ]);
        $customers = DB::table('customers')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'phone']);
        $categories = DB::table('finished_product_categories')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
        $paymentTypes = DB::table('payment_types')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'allows_change']);
        $cashAccounts = DB::table('branch_accounts as branch_account')
            ->join('accounts as account', 'account.id', '=', 'branch_account.account_id')
            ->join('branches as branch', 'branch.id', '=', 'branch_account.branch_id')
            ->where('branch_account.is_active', true)
            ->where('account.is_active', true)
            ->where('account.is_cash_bank_account', true)
            ->whereNull('branch.deleted_at')
            ->when(
                current_branch_id(),
                fn (Builder $query, int $branchId) => $query->where('branch_account.branch_id', $branchId),
            )
            ->orderBy('branch.name')
            ->orderBy('account.code')
            ->get([
                'account.id',
                'account.code',
                'account.name',
                'branch_account.branch_id',
                'branch.name as branch_name',
            ]);

        return view('backend.modules.pos_sale.form', [
            'sale' => $sale,
            'items' => $sale ? $this->documentItems($sale) : [],
            'warehouses' => $warehouses,
            'customers' => $customers,
            'categories' => $categories,
            'paymentTypes' => $paymentTypes,
            'cashAccounts' => $cashAccounts,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where(function ($query): void {
                    $query->whereNull('deleted_at')->where('is_active', true);
                    if ($branchId = current_branch_id()) {
                        $query->where('branch_id', $branchId);
                    }
                }),
            ],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')
                    ->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'sale_at' => ['required', 'date'],
            'discount_type' => ['required', Rule::in(['FLAT', 'PERCENT'])],
            'discount_value' => [
                'required',
                'numeric',
                'min:0',
                Rule::when(
                    $request->input('discount_type') === 'PERCENT',
                    ['max:100'],
                    ['max:9999999999999999.9999'],
                ),
            ],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.9999'],
            'note' => ['nullable', 'string', 'max:5000'],
            'review' => ['nullable', 'boolean'],
            'payments' => [Rule::excludeIf(! $request->boolean('review')), 'nullable', 'array'],
            'payments.*.payment_type_id' => [Rule::excludeIf(! $request->boolean('review')), 'required', 'integer'],
            'payments.*.account_id' => [Rule::excludeIf(! $request->boolean('review')), 'required', 'integer'],
            'payments.*.amount' => [
                Rule::excludeIf(! $request->boolean('review')),
                'required',
                'numeric',
                'gt:0',
                'max:9999999999999999.9999',
            ],
            'payments.*.reference_no' => [
                Rule::excludeIf(! $request->boolean('review')),
                'nullable',
                'string',
                'max:100',
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.finished_product_id' => ['required', 'integer', 'min:1'],
            'items.*.unit_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.999999'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0', 'max:9999999999999999.9999'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function header(array $data): array
    {
        return [
            'channel' => SaleChannel::Pos->value,
            'warehouse_id' => $data['warehouse_id'],
            'customer_id' => $data['customer_id'] ?? null,
            'sale_at' => $data['sale_at'],
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'tax' => $data['tax'] ?? 0,
            'note' => $data['note'] ?? null,
        ];
    }

    private function record(int $id): Sale
    {
        $sale = Sale::query()
            ->whereKey($id)
            ->where('channel', SaleChannel::Pos->value)
            ->when(
                current_branch_id(),
                fn ($query, int $branchId) => $query->where('branch_id', $branchId),
            )
            ->with([
                'branch',
                'warehouse',
                'customer',
                'items.finishedProduct.baseUnit',
                'items.unit',
                'payments.paymentType',
                'payments.account',
                'payments.customerReceipt',
                'createdBy',
                'postedBy',
            ])
            ->first();
        abort_unless($sale, 404);

        return $sale;
    }

    /** @return array<int, array<string, mixed>> */
    private function documentItems(Sale $sale): array
    {
        $stockByProduct = DB::table('finished_product_stock_balances')
            ->where('warehouse_id', $sale->warehouse_id)
            ->whereIn('finished_product_id', $sale->items->pluck('finished_product_id'))
            ->pluck('quantity', 'finished_product_id');

        return $sale->items->map(function ($item) use ($stockByProduct): array {
            return [
                'finished_product_id' => $item->finished_product_id,
                'item_text' => $item->finishedProduct->name.' ('.$item->finishedProduct->product_code.')',
                'product_name' => $item->finishedProduct->name,
                'product_code' => $item->finishedProduct->product_code,
                'stock_quantity' => $this->formatQuantity(
                    $stockByProduct->get($item->finished_product_id, 0),
                ),
                'stock_unit_code' => $item->finishedProduct->baseUnit->code,
                'unit_id' => $item->unit_id,
                'unit_text' => $item->unit->name.' ('.$item->unit->code.')',
                'unit_precision' => (int) $item->unit->precision,
                'quantity' => $this->formatQuantityForUnit($item->quantity, (int) $item->unit->precision),
                'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                'line_total' => number_format((float) $item->line_total, 2, '.', ''),
            ];
        })->all();
    }

    private function availableWarehouse(int $warehouseId): object
    {
        $warehouse = DB::table('warehouses as warehouse')
            ->join('branches as branch', 'branch.id', '=', 'warehouse.branch_id')
            ->where('warehouse.id', $warehouseId)
            ->whereNull('warehouse.deleted_at')
            ->whereNull('branch.deleted_at')
            ->where('warehouse.is_active', true)
            ->where('branch.is_active', true)
            ->when(
                current_branch_id(),
                fn (Builder $query, int $branchId) => $query->where('warehouse.branch_id', $branchId),
            )
            ->first(['warehouse.id', 'warehouse.branch_id']);
        abort_unless($warehouse, 404);

        return $warehouse;
    }

    private function statusBadge(SaleStatus $status): string
    {
        $class = match ($status) {
            SaleStatus::Draft => 'bg-dark-warning-gradient',
            SaleStatus::ReadyToPost => 'bg-dark-info-gradient',
            SaleStatus::Posted => 'bg-dark-success-gradient',
            SaleStatus::Void => 'bg-dark-danger-gradient',
        };

        return '<span class="badge text-sm fw-semibold '.$class.' px-12 py-7 radius-4 text-white">'
            .e($status->label()).'</span>';
    }

    private function paymentStatusBadge(
        SaleStatus $status,
        string $paidAmount,
        string $dueAmount,
    ): string {
        if ($status !== SaleStatus::Posted) {
            return '<span class="badge bg-secondary-focus text-secondary-600 px-10 py-6 radius-4">Pending</span>';
        }

        if (bccomp($dueAmount, '0', 4) !== 1) {
            $label = 'Paid';
            $class = 'bg-success-focus text-success-600';
        } elseif (bccomp($paidAmount, '0', 4) === 1) {
            $label = 'Partially Paid';
            $class = 'bg-warning-focus text-warning-600';
        } else {
            $label = 'Unpaid';
            $class = 'bg-danger-focus text-danger-600';
        }

        return '<span class="badge '.$class.' px-10 py-6 radius-4">'.$label.'</span>';
    }

    private function formatQuantity(mixed $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function formatQuantityForUnit(mixed $quantity, int $precision): string
    {
        $precision = max(0, min(6, $precision));

        return number_format((float) $quantity, $precision, '.', '');
    }
}
