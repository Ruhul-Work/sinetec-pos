<?php

namespace App\Http\Controllers\backend;

use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Exceptions\Finance\JournalWorkflowException;
use App\Exceptions\Sales\InvalidCustomerReceiptException;
use App\Http\Controllers\Controller;
use App\Models\backend\User;
use App\Models\Sales\CustomerReceipt;
use App\Models\Sales\Sale;
use App\Services\Sales\CustomerReceiptService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerReceivableController extends Controller
{
    public function __construct(
        private readonly CustomerReceiptService $customerReceipts,
    ) {}

    public function index(Request $request): View
    {
        return view('backend.modules.customer_receivable.index', [
            'activeTab' => $request->query('tab') === 'history' ? 'history' : 'outstanding',
        ]);
    }

    public function listAjax(Request $request): JsonResponse
    {
        if ($request->input('tab') === 'history') {
            return $this->historyList($request);
        }

        $search = trim((string) $request->input('search.value', ''));
        $query = DB::table('sales as sale')
            ->join('customers as customer', 'customer.id', '=', 'sale.customer_id')
            ->join('branches as branch', 'branch.id', '=', 'sale.branch_id')
            ->where('sale.status', 'POSTED')
            ->where('sale.due_amount', '>', 0)
            ->when(
                current_branch_id(),
                fn (Builder $builder, int $branchId) => $builder->where('sale.branch_id', $branchId),
            )
            ->select([
                'sale.id',
                'sale.sale_no',
                'sale.channel',
                'sale.sale_at',
                'sale.total',
                'sale.paid_amount',
                'sale.due_amount',
                'customer.name as customer_name',
                'customer.phone as customer_phone',
                'branch.name as branch_name',
            ]);

        $total = (clone $query)->count();
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('sale.sale_no', 'like', "%{$search}%")
                    ->orWhere('customer.name', 'like', "%{$search}%")
                    ->orWhere('customer.phone', 'like', "%{$search}%")
                    ->orWhere('branch.name', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $requestedLength = (int) $request->input('length', 10);
        $length = $requestedLength === -1 ? 1000 : min(max($requestedLength, 1), 1000);
        $rows = $query
            ->orderByDesc('sale.sale_at')
            ->orderByDesc('sale.id')
            ->skip(max((int) $request->input('start', 0), 0))
            ->take($length)
            ->get();
        $user = $request->user();

        $data = $rows->map(function (object $row) use ($user): array {
            $saleDetailsUrl = $this->saleDetailsUrl($row->channel, (int) $row->id);
            $actionClass = 'w-32-px h-32-px rounded-circle d-inline-flex '
                .'align-items-center justify-content-center';
            $actions = '<div class="d-inline-flex justify-content-end align-items-center gap-1 w-100">';

            if ($this->canViewSale($user, $row->channel)) {
                $actions .= '<a href="'.e($saleDetailsUrl).'" '
                    .'class="'.$actionClass.' bg-info-focus text-info-main" title="View Sale">'
                    .'<iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';
            }
            if ($user?->canDo('sales.customer-receivables.collect-modal', 'add')) {
                $actions .= '<button type="button" '
                    .'class="btn '.$actionClass.' bg-success-focus text-success-main AjaxModal" '
                    .'data-ajax-modal="'.e(route('sales.customer-receivables.collect-modal', $row->id)).'" '
                    .'data-size="lg" data-onsuccess="CustomerReceivables.onCollected" '
                    .'title="Collect Due">'
                    .'<iconify-icon icon="mdi:cash-plus"></iconify-icon></button>';
            }

            return [
                $row->id,
                '<a class="fw-semibold text-primary-600" href="'
                    .e($saleDetailsUrl).'">'.e($row->sale_no).'</a>',
                e(Carbon::parse($row->sale_at)->format('d M Y')),
                e($row->customer_name)
                    .($row->customer_phone ? '<br><small>'.e($row->customer_phone).'</small>' : ''),
                e($row->branch_name),
                '<span class="d-block text-end">'.number_format((float) $row->total, 2).'</span>',
                '<span class="d-block text-end text-success">'
                    .number_format((float) $row->paid_amount, 2).'</span>',
                '<span class="d-block text-end text-danger fw-semibold">'
                    .number_format((float) $row->due_amount, 2).'</span>',
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

    public function receipt(int $customerReceipt): View
    {
        $receipt = CustomerReceipt::query()
            ->with([
                'sale',
                'customer',
                'branch',
                'payments.paymentType',
                'payments.account',
                'postedBy',
            ])
            ->when(
                current_branch_id(),
                fn ($query, int $branchId) => $query->where('branch_id', $branchId),
            )
            ->findOrFail($customerReceipt);

        return view('backend.modules.customer_receivable.receipt', [
            'receipt' => $receipt,
            'saleDetailsUrl' => $this->saleDetailsUrl(
                $receipt->sale->channel->value,
                (int) $receipt->sale_id,
            ),
        ]);
    }

    public function collectModal(int $sale): View
    {
        $sale = $this->record($sale);
        abort_unless($sale->status->value === 'POSTED' && (float) $sale->due_amount > 0, 409);
        abort_unless($sale->customer_id !== null, 409);

        $paymentTypes = DB::table('payment_types')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'allows_change']);
        $accounts = DB::table('branch_accounts as assignment')
            ->join('accounts as account', 'account.id', '=', 'assignment.account_id')
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->where('assignment.branch_id', $sale->branch_id)
            ->where('assignment.is_active', true)
            ->where('account.is_active', true)
            ->where('account.is_cash_bank_account', true)
            ->where('type.code', 'ASSET')
            ->distinct()
            ->orderBy('account.code')
            ->get(['account.id', 'account.code', 'account.name']);

        return view('backend.modules.customer_receivable.collect_modal', [
            'sale' => $sale,
            'paymentTypes' => $paymentTypes,
            'accounts' => $accounts,
        ]);
    }

    public function collect(Request $request, int $sale): JsonResponse
    {
        $data = $request->validate([
            'receipt_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:5000'],
            'payments' => ['required', 'array', 'min:1'],
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
            $receipt = $this->customerReceipts->collect(
                $this->record($sale),
                $data['receipt_at'],
                $data['payments'],
                $data['note'] ?? null,
                (int) $request->user()?->id,
            );
        } catch (
            InvalidCustomerReceiptException|
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
            'msg' => 'Customer due collected and posted successfully.',
            'receipt_no' => $receipt->receipt_no,
            'receipt_url' => route('sales.customer-receivables.receipt', $receipt),
            'sale_id' => $receipt->sale_id,
        ]);
    }

    private function record(int $saleId): Sale
    {
        return Sale::query()
            ->with(['customer', 'branch'])
            ->when(
                current_branch_id(),
                fn ($query, int $branchId) => $query->where('branch_id', $branchId),
            )
            ->findOrFail($saleId);
    }

    private function historyList(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search.value', ''));
        $paymentCounts = DB::table('sale_payments')
            ->selectRaw('customer_receipt_id, COUNT(*) as payment_count')
            ->whereNotNull('customer_receipt_id')
            ->groupBy('customer_receipt_id');
        $query = DB::table('customer_receipts as receipt')
            ->join('sales as sale', 'sale.id', '=', 'receipt.sale_id')
            ->join('customers as customer', 'customer.id', '=', 'receipt.customer_id')
            ->join('branches as branch', 'branch.id', '=', 'receipt.branch_id')
            ->leftJoinSub($paymentCounts, 'payment_count', function ($join): void {
                $join->on('payment_count.customer_receipt_id', '=', 'receipt.id');
            })
            ->when(
                current_branch_id(),
                fn (Builder $builder, int $branchId) => $builder->where('receipt.branch_id', $branchId),
            )
            ->select([
                'receipt.id',
                'receipt.receipt_no',
                'receipt.receipt_at',
                'receipt.amount',
                'receipt.due_before',
                'receipt.due_after',
                'sale.id as sale_id',
                'sale.sale_no',
                'sale.channel',
                'customer.name as customer_name',
                'branch.name as branch_name',
                DB::raw('COALESCE(payment_count.payment_count, 0) as payment_count'),
            ]);

        $total = (clone $query)->count();
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('receipt.receipt_no', 'like', "%{$search}%")
                    ->orWhere('sale.sale_no', 'like', "%{$search}%")
                    ->orWhere('customer.name', 'like', "%{$search}%")
                    ->orWhere('branch.name', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $requestedLength = (int) $request->input('length', 10);
        $length = $requestedLength === -1 ? 1000 : min(max($requestedLength, 1), 1000);
        $rows = $query
            ->orderByDesc('receipt.receipt_at')
            ->orderByDesc('receipt.id')
            ->skip(max((int) $request->input('start', 0), 0))
            ->take($length)
            ->get();
        $user = $request->user();

        $data = $rows->map(function (object $row) use ($user): array {
            $saleDetailsUrl = $this->saleDetailsUrl($row->channel, (int) $row->sale_id);
            $actionClass = 'w-32-px h-32-px rounded-circle d-inline-flex '
                .'align-items-center justify-content-center';
            $actions = '<div class="d-inline-flex justify-content-end align-items-center gap-1 w-100">';

            if ($this->canViewSale($user, $row->channel)) {
                $actions .= '<a href="'.e($saleDetailsUrl).'" '
                    .'class="'.$actionClass.' bg-info-focus text-info-main" title="View Sale">'
                    .'<iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';
            }
            if ($user?->canDo('sales.customer-receivables.receipt', 'view')) {
                $actions .= '<a href="'
                    .e(route('sales.customer-receivables.receipt', $row->id)).'" target="_blank" '
                    .'class="'.$actionClass.' bg-success-focus text-success-main" title="Print Collection Receipt">'
                    .'<iconify-icon icon="mdi:printer-outline"></iconify-icon></a>';
            }

            return [
                $row->id,
                '<span class="fw-semibold text-primary-600">'.e($row->receipt_no).'</span>',
                e(Carbon::parse($row->receipt_at)->format('d M Y, h:i A')),
                e($row->customer_name).'<br><small>'.e($row->branch_name).'</small>',
                '<a class="fw-semibold" href="'.e($saleDetailsUrl).'">'
                    .e($row->sale_no).'</a>',
                '<span class="d-block text-center">'.(int) $row->payment_count.'</span>',
                '<span class="d-block text-end fw-semibold">'.number_format((float) $row->amount, 2).'</span>',
                '<span class="d-block text-end">'.number_format((float) $row->due_before, 2).'</span>',
                '<span class="d-block text-end text-success fw-semibold">'.number_format((float) $row->due_after, 2).'</span>',
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

    private function saleDetailsUrl(string $channel, int $saleId): string
    {
        $routeName = strtoupper($channel) === 'WHOLESALE'
            ? 'sales.wholesale.show'
            : 'sales.pos.show';

        return route($routeName, $saleId);
    }

    private function canViewSale(?User $user, string $channel): bool
    {
        $routeName = strtoupper($channel) === 'WHOLESALE'
            ? 'sales.wholesale.show'
            : 'sales.pos.show';

        return $user?->canDo($routeName, 'show') ?? false;
    }
}
