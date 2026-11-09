@extends('backend.layouts.master')

@section('meta')
    <title>{{ $repayment->repayment_no }}</title>
@endsection

@section('content')
    @php($isDraft = $repayment->status === 'DRAFT')

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Loan Repayment {{ $repayment->repayment_no }}</h6>
                <span
                    class="badge {{ $isDraft ? 'bg-dark-warning-gradient' : 'bg-dark-success-gradient' }} text-white px-12 py-7 radius-4"
                >
                    {{ ucfirst(strtolower($repayment->status)) }}
                </span>
            </div>
            <p class="text-muted m-0">Loan liability settlement with finance-journal control</p>
        </div>

        @if ($isDraft)
            <form
                method="POST"
                action="{{ route('finance.loan-repayments.post', $repayment) }}"
                class="js-swal-confirm"
                data-confirm-title="Post this repayment?"
                data-confirm-text="A Payment Voucher Draft will be created."
                data-confirm-button="Yes, post repayment"
            >
                @csrf
                @method('PATCH')
                <button class="btn btn-success btn-sm text-white d-inline-flex align-items-center gap-1">
                    <iconify-icon icon="mdi:cash-minus"></iconify-icon>
                    <span>Post Repayment</span>
                </button>
            </form>
        @endif
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Repayment Information</h5>
        </div>
        <div class="card-body row g-3">
            <div class="col-md-3">
                <small class="text-muted d-block">Loan Receipt</small>
                <strong>{{ $repayment->loanReceipt->loan_no }}</strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted d-block">Date</small>
                <strong>{{ $repayment->repayment_date->format('d M Y') }}</strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted d-block">Paid From</small>
                <strong>
                    {{ $repayment->cashBankAccount->code }} — {{ $repayment->cashBankAccount->name }}
                </strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted d-block">Amount</small>
                <strong class="text-danger-main">{{ number_format($repayment->amount, 2) }}</strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted d-block">Finance Journal</small>
                @if ($journal)
                    <a
                        class="fw-semibold text-info-main"
                        href="{{ route('finance.journals.show', $journal->id) }}"
                    >
                        {{ $journal->voucher_no }}
                    </a>
                @else
                    <span class="text-muted">Not created</span>
                @endif
            </div>
        </div>
    </div>
@endsection
