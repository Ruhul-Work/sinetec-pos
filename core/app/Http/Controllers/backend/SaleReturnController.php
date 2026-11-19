<?php

namespace App\Http\Controllers\backend;

use App\Enums\Sales\SaleReturnStatus;
use App\Enums\Sales\SaleStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Exceptions\Finance\JournalWorkflowException;
use App\Exceptions\Inventory\InvalidStockPostingException;
use App\Exceptions\Sales\InvalidSaleReturnException;
use App\Http\Controllers\Controller;
use App\Models\Sales\Sale;
use App\Models\Sales\SaleItem;
use App\Models\Sales\SaleReturn;
use App\Services\Sales\SaleReturnService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SaleReturnController extends Controller
{
    public function __construct(private readonly SaleReturnService $saleReturns) {}

    public function index(): View
    {
        return view('backend.modules.sale_return.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $lineCounts = DB::table('sale_return_items')
            ->selectRaw('sale_return_id, COUNT(*) as line_count')
            ->groupBy('sale_return_id');

        $query = DB::table('sale_returns as sale_return')
            ->join('sales as sale', 'sale.id', '=', 'sale_return.sale_id')
            ->join('branches as branch', 'branch.id', '=', 'sale_return.branch_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'sale_return.warehouse_id')
            ->leftJoin('customers as customer', 'customer.id', '=', 'sale.customer_id')
            ->leftJoinSub($lineCounts, 'line_count', function ($join): void {
                $join->on('line_count.sale_return_id', '=', 'sale_return.id');
            })
            ->when(
                current_branch_id(),
                fn (Builder $builder, int $branchId) => $builder->where('sale_return.branch_id', $branchId),
            )
            ->select([
                'sale_return.id',
                'sale_return.return_no',
                'sale_return.return_at',
                'sale_return.status',
                'sale_return.total',
                'sale.id as sale_id',
                'sale.sale_no',
                'sale.channel',
                'branch.name as branch_name',
                'warehouse.name as warehouse_name',
                'warehouse.code as warehouse_code',
                'customer.name as customer_name',
                DB::raw('COALESCE(line_count.line_count, 0) as line_count'),
            ]);

        $total = (clone $query)->count();
        $status = strtoupper(trim((string) $request->input('status')));
        $search = trim((string) $request->input('search.value'));

        if (SaleReturnStatus::tryFrom($status)) {
            $query->where('sale_return.status', $status);
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('sale_return.return_no', 'like', "%{$search}%")
                    ->orWhere('sale.sale_no', 'like', "%{$search}%")
                    ->orWhere('customer.name', 'like', "%{$search}%")
                    ->orWhere('warehouse.name', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $length = min(max((int) $request->input('length', 10), 1), 1000);
        $rows = $query
            ->orderByDesc('sale_return.id')
            ->skip(max((int) $request->input('start', 0), 0))
            ->take($length)
            ->get();

        $data = $rows->map(fn (object $row): array => $this->tableRow($row, $request))->all();

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $data,
        ]);
    }

    public function create(Request $request): View
    {
        $saleId = $request->integer('sale_id') ?: (int) old('sale_id');
        $sale = $saleId > 0
            ? $this->sourceSale($saleId)
            : null;

        return $this->formView(null, $sale);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $saleReturn = $this->saleReturns->createDraft(
                $data,
                $data['items'],
                $request->user()?->id,
            );
        } catch (InvalidSaleReturnException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('sales.returns.show', $saleReturn)
            ->with('success', 'Sales Return Draft created. Stock and Finance were not changed.');
    }

    public function show(int $saleReturn): View
    {
        return view('backend.modules.sale_return.show', [
            'saleReturn' => $this->record($saleReturn),
        ]);
    }

    public function edit(int $saleReturn): View
    {
        $saleReturn = $this->record($saleReturn);
        abort_unless(
            $saleReturn->status === SaleReturnStatus::Draft,
            409,
            'Only a Draft Sales Return can be edited.',
        );

        return $this->formView($saleReturn, $saleReturn->sale);
    }

    public function update(Request $request, int $saleReturn): RedirectResponse
    {
        $saleReturn = $this->record($saleReturn);
        $data = $this->validated($request);

        try {
            $saleReturn = $this->saleReturns->updateDraft(
                $saleReturn,
                $data,
                $data['items'],
            );
        } catch (InvalidSaleReturnException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('sales.returns.show', $saleReturn)
            ->with('success', 'Sales Return Draft updated. Stock and Finance were not changed.');
    }

    public function postModal(int $saleReturn): View
    {
        $saleReturn = $this->record($saleReturn);
        abort_unless(
            $saleReturn->status === SaleReturnStatus::Draft,
            409,
            'Only a Draft Sales Return can be posted.',
        );

        $paymentTypes = DB::table('payment_types')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
        $accounts = DB::table('branch_accounts as assignment')
            ->join('accounts as account', 'account.id', '=', 'assignment.account_id')
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->where('assignment.branch_id', $saleReturn->branch_id)
            ->where('assignment.is_active', true)
            ->where('account.is_active', true)
            ->where('account.is_cash_bank_account', true)
            ->where('type.code', 'ASSET')
            ->distinct()
            ->orderBy('account.code')
            ->get(['account.id', 'account.code', 'account.name']);

        return view('backend.modules.sale_return.post_modal', [
            'saleReturn' => $saleReturn,
            'summary' => $this->saleReturns->postingSummary($saleReturn),
            'paymentTypes' => $paymentTypes,
            'accounts' => $accounts,
        ]);
    }

    public function post(Request $request, int $saleReturn): JsonResponse
    {
        $data = $request->validate([
            'payments' => ['nullable', 'array'],
            'payments.*.payment_type_id' => [
                'required',
                'integer',
                Rule::exists('payment_types', 'id'),
            ],
            'payments.*.account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id'),
            ],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $saleReturn = $this->saleReturns->post(
                $this->record($saleReturn),
                $data['payments'] ?? [],
                (int) $request->user()?->id,
            );
        } catch (
            InvalidSaleReturnException|
            InvalidStockPostingException|
            FinanceConfigurationException|
            InvalidJournalDraftException|
            JournalWorkflowException $exception
        ) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'msg' => 'Sales Return posted. Stock, customer balance and Finance were updated.',
            'redirect_url' => route('sales.returns.show', $saleReturn),
        ]);
    }

    public function destroy(int $saleReturn): JsonResponse
    {
        try {
            $this->saleReturns->deleteDraft($this->record($saleReturn));
        } catch (InvalidSaleReturnException $exception) {
            return response()->json([
                'success' => false,
                'msg' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'msg' => 'Sales Return Draft deleted.',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'sale_id' => [
                'required',
                'integer',
                Rule::exists('sales', 'id')->where(
                    fn ($query) => $query->where('status', SaleStatus::Posted->value),
                ),
            ],
            'return_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:now'],
            'reason' => ['required', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer', 'exists:sale_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999.999999'],
        ]);
    }

    private function formView(?SaleReturn $saleReturn, ?Sale $sale): View
    {
        if ($sale) {
            $sale = $this->sourceSale((int) $sale->id);
        }

        $sales = Sale::query()
            ->with(['customer:id,name', 'warehouse:id,name,code', 'items'])
            ->where('status', SaleStatus::Posted)
            ->when(current_branch_id(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->orderByDesc('sale_at')
            ->limit(250)
            ->get()
            ->filter(function (Sale $candidate): bool {
                return $candidate->items->contains(
                    fn (SaleItem $item) => bccomp(
                        $this->saleReturns->availableBaseQuantity($item),
                        '0.000000',
                        6,
                    ) === 1,
                );
            })
            ->values();

        if ($sale && ! $sales->contains('id', $sale->id)) {
            $sales->prepend($sale);
        }

        return view('backend.modules.sale_return.form', [
            'saleReturn' => $saleReturn,
            'sale' => $sale,
            'sales' => $sales,
            'sourceItems' => $sale ? $this->sourceItems($sale, $saleReturn) : [],
        ]);
    }

    private function sourceSale(int $saleId): Sale
    {
        $sale = Sale::query()
            ->whereKey($saleId)
            ->where('status', SaleStatus::Posted)
            ->when(current_branch_id(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->with([
                'branch',
                'warehouse',
                'customer',
                'items.finishedProduct.baseUnit',
                'items.unit',
            ])
            ->first();

        abort_unless($sale, 404);

        return $sale;
    }

    private function record(int $saleReturnId): SaleReturn
    {
        $saleReturn = SaleReturn::query()
            ->whereKey($saleReturnId)
            ->when(current_branch_id(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->with([
                'sale.customer',
                'branch',
                'warehouse',
                'items.saleItem',
                'items.finishedProduct.baseUnit',
                'items.unit',
                'payments.paymentType',
                'payments.account',
                'createdBy',
                'postedBy',
            ])
            ->first();

        abort_unless($saleReturn, 404);

        return $saleReturn;
    }

    /** @return array<int, array<string, mixed>> */
    private function sourceItems(Sale $sale, ?SaleReturn $saleReturn): array
    {
        $selectedQuantities = $saleReturn?->items->pluck('quantity', 'sale_item_id') ?? collect();

        return $sale->items->map(function (SaleItem $item) use ($selectedQuantities): ?array {
            $availableBaseQuantity = $this->saleReturns->availableBaseQuantity($item);
            if (bccomp($availableBaseQuantity, '0.000000', 6) !== 1) {
                return null;
            }

            $conversion = (string) $item->conversion_to_base;
            $availableQuantity = bcdiv($availableBaseQuantity, $conversion, 6);
            $precision = max(0, min(6, (int) $item->unit->precision));

            return [
                'sale_item_id' => $item->id,
                'name' => $item->finishedProduct->name,
                'code' => $item->finishedProduct->product_code,
                'unit_text' => $item->unit->name.' ('.$item->unit->code.')',
                'unit_code' => $item->unit->code,
                'unit_precision' => $precision,
                'sold_quantity' => $this->formatQuantity($item->quantity, $precision),
                'available_quantity' => $this->formatQuantity($availableQuantity, $precision),
                'available_quantity_raw' => $availableQuantity,
                'unit_price' => (string) $item->unit_price,
                'selected_quantity' => $this->formatQuantity(
                    $selectedQuantities->get($item->id, 0),
                    $precision,
                ),
            ];
        })->filter()->values()->all();
    }

    private function tableRow(object $row, Request $request): array
    {
        $status = SaleReturnStatus::from($row->status);
        $actionClass = 'w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center';
        $actions = '<div class="d-inline-flex justify-content-end align-items-center gap-1 w-100">';

        if ($request->user()?->canDo('sales.returns.show', 'show')) {
            $actions .= '<a href="'.e(route('sales.returns.show', $row->id)).'" '
                .'class="'.$actionClass.' bg-info-focus text-info-main" title="View">'
                .'<iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';
        }

        if ($status === SaleReturnStatus::Draft
            && $request->user()?->canDo('sales.returns.edit', 'edit')) {
            $actions .= '<a href="'.e(route('sales.returns.edit', $row->id)).'" '
                .'class="'.$actionClass.' bg-success-focus text-success-main" title="Edit">'
                .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
        }

        if ($status === SaleReturnStatus::Draft
            && $request->user()?->canDo('sales.returns.destroy', 'delete')) {
            $actions .= '<button type="button" data-url="'.e(route('sales.returns.destroy', $row->id)).'" '
                .'class="btn '.$actionClass.' bg-danger-focus text-danger-main btn-sale-return-delete" title="Delete">'
                .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></button>';
        }

        $actions .= '</div>';

        return [
            $row->id,
            '<a class="fw-semibold text-primary-600" href="'.e(route('sales.returns.show', $row->id)).'">'
                .e($row->return_no).'</a>',
            e(Carbon::parse($row->return_at)->format('d M Y, h:i A')),
            '<span class="badge bg-primary-focus text-primary-main radius-4">'.e(ucfirst(strtolower($row->channel))).'</span>',
            e($row->customer_name ?: 'Walk-in Customer'),
            '<a class="fw-semibold" href="'.e($this->saleDetailsRoute($row->channel, $row->sale_id)).'">'
                .e($row->sale_no).'</a>',
            '<strong>'.e($row->warehouse_name).'</strong><br><code>'.e($row->warehouse_code).'</code>',
            '<span class="d-block text-center">'.(int) $row->line_count.'</span>',
            '<span class="d-block text-end fw-semibold">'.number_format((float) $row->total, 2).'</span>',
            $this->statusBadge($status),
            $actions,
        ];
    }

    private function statusBadge(SaleReturnStatus $status): string
    {
        $class = match ($status) {
            SaleReturnStatus::Draft => 'bg-dark-warning-gradient',
            SaleReturnStatus::Posted => 'bg-dark-success-gradient',
            SaleReturnStatus::Void => 'bg-dark-danger-gradient',
        };

        return '<span class="badge text-sm fw-semibold '.$class.' px-12 py-7 radius-4 text-white">'
            .e($status->label()).'</span>';
    }

    private function saleDetailsRoute(string $channel, int $saleId): string
    {
        $route = strtoupper($channel) === 'WHOLESALE'
            ? 'sales.wholesale.show'
            : 'sales.pos.show';

        return route($route, $saleId);
    }

    private function formatQuantity(mixed $quantity, int $precision): string
    {
        if ($precision === 0) {
            return (string) (int) round((float) $quantity);
        }

        return rtrim(rtrim(number_format((float) $quantity, $precision, '.', ''), '0'), '.');
    }
}
