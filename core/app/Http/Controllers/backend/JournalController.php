<?php

namespace App\Http\Controllers\backend;

use App\Enums\Finance\JournalStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Exceptions\Finance\JournalWorkflowException;
use App\Http\Controllers\Controller;
use App\Models\backend\JournalEntry;
use App\Models\Purchase\PurchasePayment;
use App\Models\Purchase\PurchaseReceipt;
use App\Models\Purchase\PurchaseReturn;
use App\Services\Finance\JournalWorkflowService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class JournalController extends Controller
{
    public function __construct(private readonly JournalWorkflowService $workflow) {}

    public function index(): View
    {
        return view('backend.modules.journals.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['je.id', 'je.voucher_no', 'je.entry_date', 'b.name', 'vt.name', 'je.source_type', 'totals.line_count', 'totals.total_debit', 'totals.total_credit', 'je.status', 'je.id'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $direction = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));
        $status = strtoupper(trim((string) $request->input('status', '')));
        $sourceType = trim((string) $request->input('source_type', ''));

        $totals = DB::table('journal_entry_lines')
            ->select('journal_entry_id', DB::raw('COUNT(*) as line_count'), DB::raw('SUM(debit) as total_debit'), DB::raw('SUM(credit) as total_credit'))
            ->groupBy('journal_entry_id');
        $query = DB::table('journal_entries as je')
            ->join('voucher_types as vt', 'vt.id', '=', 'je.voucher_type_id')
            ->join('branches as b', 'b.id', '=', 'je.branch_id')
            ->join('fiscal_years as fy', 'fy.id', '=', 'je.fiscal_year_id')
            ->leftJoinSub($totals, 'totals', 'totals.journal_entry_id', '=', 'je.id')
            ->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('je.branch_id', $branchId))
            ->select([
                'je.id', 'je.voucher_no', 'je.entry_date', 'je.source_type', 'je.source_id', 'je.status', 'je.approved_at',
                'je.narration', 'vt.name as voucher_type_name', 'b.name as branch_name', 'b.code as branch_code',
                'fy.name as fiscal_year_name', DB::raw('COALESCE(totals.line_count, 0) as line_count'),
                DB::raw('COALESCE(totals.total_debit, 0) as total_debit'), DB::raw('COALESCE(totals.total_credit, 0) as total_credit'),
            ]);

        $total = (clone $query)->count();
        if (JournalStatus::tryFrom($status)) {
            $query->where('je.status', $status);
        }
        if (in_array($sourceType, ['purchase_receipt', 'purchase_return', 'purchase_payment', 'journal_reversal'], true)) {
            $query->where('je.source_type', $sourceType);
        }
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('je.voucher_no', 'like', "%{$search}%")
                    ->orWhere('je.narration', 'like', "%{$search}%")
                    ->orWhere('vt.name', 'like', "%{$search}%")
                    ->orWhere('b.name', 'like', "%{$search}%")
                    ->orWhere('fy.name', 'like', "%{$search}%");
            });
        }
        $filtered = (clone $query)->count();
        $rows = $query->orderBy($columns[$orderIndex] ?? 'je.id', $direction)->skip($start)->take($length)->get();
        $sources = $this->sourceMaps($rows->all());

        $data = $rows->map(function (object $row) use ($sources): array {
            $status = JournalStatus::from($row->status);
            $source = $this->sourcePresentation($row->source_type, (int) $row->source_id, $sources);
            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100"><a href="'.e(route('finance.journals.show', $row->id)).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main" title="View journal"><iconify-icon icon="mdi:eye-outline"></iconify-icon></a></div>';

            return [
                $row->id,
                '<a class="fw-semibold text-primary-600" href="'.e(route('finance.journals.show', $row->id)).'">'.e($row->voucher_no).'</a><br><small class="text-muted">'.e($row->fiscal_year_name).'</small>',
                e(date('d M Y', strtotime($row->entry_date))),
                '<strong>'.e($row->branch_name).'</strong><br><code>'.e($row->branch_code).'</code>',
                e($row->voucher_type_name),
                $source['html'],
                (int) $row->line_count,
                '<span class="fw-semibold">'.number_format((float) $row->total_debit, 2).'</span>',
                '<span class="fw-semibold">'.number_format((float) $row->total_credit, 2).'</span>',
                '<span class="badge text-sm fw-semibold '.$status->badgeClass().' px-12 py-7 radius-4 text-white">'.e($status->label()).'</span>'
                .($status === JournalStatus::Draft && $row->approved_at ? '<br><small class="text-success-main fw-semibold">Approved</small>' : ''),
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

    public function show(int $journal): View
    {
        $journal = JournalEntry::query()
            ->when(current_branch_id(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->with([
                'voucherType', 'branch', 'fiscalYear', 'createdBy', 'approvedBy', 'postedBy', 'voidedBy', 'reversal', 'reversalOf',
                'lines' => fn ($query) => $query->with(['account.type'])->orderBy('id'),
            ])
            ->findOrFail($journal);
        $source = $this->sourceForJournal($journal);
        $totalDebit = $journal->lines->reduce(fn (float $total, $line): float => $total + (float) $line->debit, 0.0);
        $totalCredit = $journal->lines->reduce(fn (float $total, $line): float => $total + (float) $line->credit, 0.0);

        return view('backend.modules.journals.show', compact('journal', 'source', 'totalDebit', 'totalCredit'));
    }

    public function approve(int $journal, Request $request): RedirectResponse
    {
        try {
            $this->workflow->approve($this->record($journal), $request->user()?->id);
        } catch (JournalWorkflowException|FinanceConfigurationException|InvalidJournalDraftException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Journal approved and ready for posting.');
    }

    public function post(int $journal, Request $request): RedirectResponse
    {
        try {
            $this->workflow->post($this->record($journal), $request->user()?->id);
        } catch (JournalWorkflowException|FinanceConfigurationException|InvalidJournalDraftException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Journal posted. It now affects account balances.');
    }

    public function void(int $journal, Request $request): RedirectResponse
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:1000']]);
        try {
            $this->workflow->void($this->record($journal), $data['void_reason'], $request->user()?->id);
        } catch (JournalWorkflowException|FinanceConfigurationException|InvalidJournalDraftException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Draft journal voided. It does not affect account balances.');
    }

    public function reverse(int $journal, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reversal_date' => ['required', 'date'],
            'reversal_reason' => ['required', 'string', 'max:1000'],
        ]);
        try {
            $reversal = $this->workflow->createReversalDraft(
                $this->record($journal),
                $data['reversal_date'],
                $data['reversal_reason'],
                $request->user()?->id,
            );
        } catch (JournalWorkflowException|FinanceConfigurationException|InvalidJournalDraftException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('finance.journals.show', $reversal)->with('success', 'Reversal draft created. Approve and post it to reverse the original journal balance.');
    }

    /** @param array<int, object> $rows @return array<string, array<int, object>> */
    private function sourceMaps(array $rows): array
    {
        $receiptIds = collect($rows)->where('source_type', 'purchase_receipt')->pluck('source_id')->filter()->unique();
        $returnIds = collect($rows)->where('source_type', 'purchase_return')->pluck('source_id')->filter()->unique();
        $paymentIds = collect($rows)->where('source_type', 'purchase_payment')->pluck('source_id')->filter()->unique();

        return [
            'purchase_receipt' => PurchaseReceipt::query()->whereIn('id', $receiptIds)->get(['id', 'receipt_no'])->keyBy('id')->all(),
            'purchase_return' => PurchaseReturn::query()->whereIn('id', $returnIds)->get(['id', 'return_no'])->keyBy('id')->all(),
            'purchase_payment' => PurchasePayment::query()->whereIn('id', $paymentIds)->get(['id', 'payment_no'])->keyBy('id')->all(),
            'journal_reversal' => JournalEntry::query()->whereIn('id', collect($rows)->where('source_type', 'journal_reversal')->pluck('source_id')->filter()->unique())->get(['id', 'voucher_no'])->keyBy('id')->all(),
        ];
    }

    /** @param array<string, array<int, object>> $sources @return array{label: string, number: string, url: ?string, html: string} */
    private function sourcePresentation(?string $type, int $id, array $sources): array
    {
        $record = $sources[$type][$id] ?? null;
        [$label, $number, $url] = match ($type) {
            'purchase_receipt' => ['Purchase Receipt', $record?->receipt_no ?? '#'.$id, $record ? route('purchase.receipts.show', $id) : null],
            'purchase_return' => ['Purchase Return', $record?->return_no ?? '#'.$id, $record ? route('purchase.returns.show', $id) : null],
            'purchase_payment' => ['Supplier Payment', $record?->payment_no ?? '#'.$id, $record ? route('purchase.payments.show', $id) : null],
            'journal_reversal' => ['Journal Reversal', $record?->voucher_no ?? '#'.$id, $record ? route('finance.journals.show', $id) : null],
            default => [str($type ?: 'Manual')->replace('_', ' ')->title()->toString(), '#'.$id, null],
        };
        $content = '<small class="text-muted d-block">'.e($label).'</small>'.($url
                ? '<a class="fw-semibold text-info-main" href="'.e($url).'">'.e($number).'</a>'
                : '<span class="fw-semibold">'.e($number).'</span>');

        return ['label' => $label, 'number' => $number, 'url' => $url, 'html' => $content];
    }

    /** @return array{label: string, number: string, url: ?string} */
    private function sourceForJournal(JournalEntry $journal): array
    {
        $sources = $this->sourceMaps([(object) [
            'source_type' => $journal->source_type,
            'source_id' => $journal->source_id,
        ]]);
        $source = $this->sourcePresentation($journal->source_type, (int) $journal->source_id, $sources);
        unset($source['html']);

        return $source;
    }

    private function record(int $journal): JournalEntry
    {
        return JournalEntry::query()
            ->when(current_branch_id(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->findOrFail($journal);
    }
}
