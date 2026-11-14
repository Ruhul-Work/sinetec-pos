@extends('backend.layouts.master')

@section('meta')
    <title>{{ $expense->expense_no }}</title>
@endsection

@section('content')
    @php
        $isDraft = $expense->status === \App\Enums\Finance\ExpenseStatus::Draft;
        $isProductionSettlement = (bool) $expense->productionBatch;
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Expense Payment {{ $expense->expense_no }}</h6>
                <span class="badge {{ $expense->status->badgeClass() }} text-white px-12 py-7 radius-4">
                    {{ $expense->status->label() }}
                </span>
            </div>
            <p class="text-muted m-0">
                {{ $isProductionSettlement
                    ? 'Production payable settlement with controlled journal posting'
                    : 'Direct Cash/Bank expense document with controlled journal posting' }}
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2">
            @if ($isDraft)
                @perm('finance.expenses.void', 'edit')
                    <button
                        type="button"
                        class="btn btn-outline-danger btn-sm px-14 py-8 radius-8 app-action-button d-inline-flex align-items-center gap-1"
                        data-bs-toggle="modal"
                        data-bs-target="#voidExpenseModal"
                    >
                        <iconify-icon icon="mdi:cancel"></iconify-icon>
                        <span>Void Draft</span>
                    </button>
                @endperm

                @perm('finance.expenses.post', 'edit')
                    <form
                        method="POST"
                        action="{{ route('finance.expenses.post', $expense) }}"
                        class="d-flex js-swal-confirm"
                        data-confirm-title="Record this expense payment?"
                        data-confirm-text="Creates a finance Journal Draft. Cash/Bank changes only after that journal is approved and posted."
                        data-confirm-button="Yes, record payment"
                        data-confirm-color="#0f766e"
                    >
                        @csrf
                        @method('PATCH')
                        <button
                            type="submit"
                            class="btn btn-success btn-sm px-14 py-8 radius-8 text-white app-action-button
                                d-inline-flex align-items-center gap-1"
                        >
                            <iconify-icon icon="mdi:cash-check"></iconify-icon>
                            <span>Record Payment</span>
                        </button>
                    </form>
                @endperm
            @endif

            <a
                href="{{ route('finance.expenses.index') }}"
                class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button
                    d-inline-flex align-items-center gap-1"
            >
                <iconify-icon icon="mdi:arrow-left"></iconify-icon>
                <span>Back</span>
            </a>
        </div>
    </div>

    <div class="card mb-24">
        <div class="card-header">
            <h5 class="card-title mb-0">Expense Information</h5>
        </div>
        <div class="card-body row g-3">
            <div class="col-xl-3 col-md-4">
                <small class="text-muted d-block">Date</small>
                <strong>{{ $expense->expense_date->format('d M Y') }}</strong>
            </div>
            <div class="col-xl-3 col-md-4">
                <small class="text-muted d-block">Branch</small>
                <strong>{{ $expense->branch->name }}</strong>
            </div>
            <div class="col-xl-3 col-md-4">
                <small class="text-muted d-block">Category</small>
                <strong>{{ $expense->category?->name ?: 'Legacy Production Settlement' }}</strong>
            </div>
            <div class="col-xl-3 col-md-4">
                <small class="text-muted d-block">Amount</small>
                <strong class="text-danger-main">{{ number_format((float) $expense->amount, 2) }}</strong>
            </div>
            <div class="col-xl-4 col-md-6">
                <small class="text-muted d-block">Account Debited</small>
                @if ($isProductionSettlement)
                    <strong>Legacy Production Expense Payable</strong>
                @else
                    <strong>
                        {{ $expense->category->expenseAccount->code }} —
                        {{ $expense->category->expenseAccount->name }}
                    </strong>
                @endif
            </div>
            <div class="col-xl-4 col-md-6">
                <small class="text-muted d-block">Cash / Bank Credited</small>
                <strong>{{ $expense->payment->account->code }} — {{ $expense->payment->account->name }}</strong>
            </div>
            <div class="col-xl-4 col-md-6">
                <small class="text-muted d-block">Payment Type</small>
                <strong>{{ $expense->payment->paymentType->name }}</strong>
            </div>
            <div class="col-xl-4 col-md-6">
                <small class="text-muted d-block">Paid To</small>
                <strong>{{ $expense->payee ?: '—' }}</strong>
            </div>
            <div class="col-xl-4 col-md-6">
                <small class="text-muted d-block">Reference No.</small>
                <strong>{{ $expense->payment->reference_no ?: '—' }}</strong>
            </div>
            <div class="col-xl-4 col-md-6">
                <small class="text-muted d-block">Finance Journal</small>
                @if ($expense->journal)
                    <a class="fw-semibold text-info-main" href="{{ route('finance.journals.show', $expense->journal) }}">
                        {{ $expense->journal->voucher_no }}
                        <span class="text-muted">({{ $expense->journal->status->label() }})</span>
                    </a>
                @else
                    <span class="text-muted">Created after payment is recorded</span>
                @endif
            </div>
            @if ($expense->description)
                <div class="col-12">
                    <small class="text-muted d-block">Description</small>
                    <span>{{ $expense->description }}</span>
                </div>
            @endif
        </div>
    </div>

    @if ($isProductionSettlement)
        <div class="card mb-24">
            <div class="card-header">
                <h5 class="card-title mb-0">Legacy Production Cost Settlement Lines</h5>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-20">
                    <div class="col-md-4">
                        <small class="text-muted d-block">Batch</small>
                        <a class="fw-semibold text-info-main" href="{{ route('production.part-productions.show', $expense->productionBatch) }}">
                            {{ $expense->productionBatch->batch_no }}
                        </a>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Output Part</small>
                        <strong>{{ $expense->productionBatch->bom->part->name }}</strong>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Selected Lines</small>
                        <strong>{{ $expense->items->count() }}</strong>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table bordered-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Production Cost Category</th>
                                <th>Note</th>
                                <th class="text-end">Paid Now</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($expense->items as $item)
                                <tr>
                                    <td>{{ $item->productionExpense?->category?->name }}</td>
                                    <td>{{ $item->note ?: '—' }}</td>
                                    <td class="text-end fw-semibold">{{ number_format((float) $item->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if ($isDraft)
        <div class="modal fade" id="voidExpenseModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" action="{{ route('finance.expenses.void', $expense) }}" class="modal-content">
                    @csrf
                    @method('PATCH')
                    <div class="modal-header">
                        <h5 class="modal-title">Void Draft Expense</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted text-sm">This Draft has not affected Cash/Bank or the Account Ledger.</p>
                        <label class="form-label">Reason <span class="text-danger">*</span></label>
                        <textarea name="void_reason" class="form-control" rows="3" maxlength="1000" required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Void Draft</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
