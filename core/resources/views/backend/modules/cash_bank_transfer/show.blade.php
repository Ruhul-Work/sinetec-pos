@extends('backend.layouts.master')

@section('meta')
    <title>{{ $transfer->transfer_no }}</title>
@endsection

@section('content')
    @php($isDraft = $transfer->status === 'DRAFT')

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Cash / Bank Transfer {{ $transfer->transfer_no }}</h6>
                <span
                    class="badge {{ $isDraft ? 'bg-dark-warning-gradient' : 'bg-dark-success-gradient' }} text-white px-12 py-7 radius-4"
                >
                    {{ ucfirst(strtolower($transfer->status)) }}
                </span>
            </div>
            <p class="text-muted m-0">Same-branch funds movement with finance-journal control</p>
        </div>

        <div class="d-flex gap-2">
            @if ($isDraft)
                <form
                    method="POST"
                    action="{{ route('finance.cash-bank-transfers.post', $transfer) }}"
                    class="js-swal-confirm"
                    data-confirm-title="Post this transfer?"
                    data-confirm-text="A Transfer Voucher Draft will be created."
                    data-confirm-button="Yes, post transfer"
                >
                    @csrf
                    @method('PATCH')
                    <button class="btn btn-success btn-sm text-white d-inline-flex align-items-center gap-1">
                        <iconify-icon icon="mdi:bank-transfer"></iconify-icon>
                        <span>Post Transfer</span>
                    </button>
                </form>
            @endif

            <a
                href="{{ route('finance.cash-bank-transfers.index') }}"
                class="btn btn-outline-secondary btn-sm"
            >
                Back
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Transfer Information</h5>
        </div>
        <div class="card-body row g-3">
            <div class="col-md-3">
                <small class="text-muted d-block">Date</small>
                <strong>{{ $transfer->transfer_date->format('d M Y') }}</strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted d-block">Branch</small>
                <strong>{{ $transfer->branch->name }}</strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted d-block">Amount</small>
                <strong>{{ number_format($transfer->amount, 2) }}</strong>
            </div>
            <div class="col-md-3">
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
            <div class="col-md-6">
                <small class="text-muted d-block">From — Credit</small>
                <strong>{{ $transfer->fromAccount->code }} — {{ $transfer->fromAccount->name }}</strong>
            </div>
            <div class="col-md-6">
                <small class="text-muted d-block">To — Debit</small>
                <strong>{{ $transfer->toAccount->code }} — {{ $transfer->toAccount->name }}</strong>
            </div>
        </div>
    </div>
@endsection
