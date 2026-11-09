@extends('backend.layouts.master')

@section('meta')
    <title>{{ $withdrawal->withdrawal_no }}</title>
@endsection

@section('content')
    @php($isDraft = $withdrawal->status === \App\Enums\Finance\CapitalWithdrawalStatus::Draft)

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Capital Withdrawal {{ $withdrawal->withdrawal_no }}</h6>
                <span class="badge {{ $withdrawal->status->badgeClass() }} text-white px-12 py-7 radius-4">
                    {{ $withdrawal->status->label() }}
                </span>
            </div>
            <p class="text-muted m-0">Owner / Partner drawings and finance-journal control</p>
        </div>

        <div class="d-flex gap-2">
            @if ($isDraft)
                @perm('finance.capital-withdrawals.post', 'edit')
                    <form
                        method="POST"
                        action="{{ route('finance.capital-withdrawals.post', $withdrawal) }}"
                        class="js-swal-confirm"
                        data-confirm-title="Post this withdrawal?"
                        data-confirm-text="A finance journal Draft will be created."
                        data-confirm-button="Yes, post withdrawal"
                    >
                        @csrf
                        @method('PATCH')
                        <button class="btn btn-success btn-sm text-white d-inline-flex align-items-center gap-1">
                            <iconify-icon icon="mdi:cash-minus"></iconify-icon>
                            <span>Post Withdrawal</span>
                        </button>
                    </form>
                @endperm

                @perm('finance.capital-withdrawals.void', 'edit')
                    <button
                        class="btn btn-outline-danger btn-sm"
                        data-bs-toggle="modal"
                        data-bs-target="#voidModal"
                    >
                        Void Draft
                    </button>
                @endperm
            @endif

            <a
                href="{{ route('finance.capital-withdrawals.index') }}"
                class="btn btn-outline-secondary btn-sm"
            >
                Back
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Withdrawal Information</h5>
        </div>
        <div class="card-body row g-3">
            <div class="col-md-3">
                <small class="text-muted d-block">Type</small>
                <strong>
                    {{ $withdrawal->withdrawal_type === 'PARTNER_WITHDRAWAL' ? 'Partner Withdrawal' : 'Owner Withdrawal' }}
                </strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted d-block">Date</small>
                <strong>{{ $withdrawal->withdrawal_date->format('d M Y') }}</strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted d-block">Amount</small>
                <strong class="text-danger-main">{{ number_format($withdrawal->amount, 2) }}</strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted d-block">Branch</small>
                <strong>{{ $withdrawal->branch->name }}</strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted d-block">Cash / Bank Credited</small>
                <strong>
                    {{ $withdrawal->cashBankAccount->code }} — {{ $withdrawal->cashBankAccount->name }}
                </strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted d-block">Drawings Debited</small>
                <strong>
                    {{ $withdrawal->drawingsAccount->code }} — {{ $withdrawal->drawingsAccount->name }}
                </strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted d-block">Finance Journal</small>
                @if ($journal)
                    <a
                        class="fw-semibold text-info-main"
                        href="{{ route('finance.journals.show', $journal) }}"
                    >
                        {{ $journal->voucher_no }} ({{ $journal->status->label() }})
                    </a>
                @else
                    <span class="text-muted">Not created</span>
                @endif
            </div>

            @if ($withdrawal->note)
                <div class="col-12">
                    <small class="text-muted d-block">Note</small>
                    {{ $withdrawal->note }}
                </div>
            @endif
        </div>
    </div>

    @if ($isDraft)
        <div class="modal fade" id="voidModal">
            <div class="modal-dialog">
                <form
                    method="POST"
                    action="{{ route('finance.capital-withdrawals.void', $withdrawal) }}"
                    class="modal-content"
                >
                    @csrf
                    @method('PATCH')
                    <div class="modal-header">
                        <h5>Void Draft Withdrawal</h5>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">Reason <span class="text-danger">*</span></label>
                        <textarea name="void_reason" class="form-control" required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-danger">Void Draft</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
