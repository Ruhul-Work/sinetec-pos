@extends('backend.layouts.master')

@section('meta')
  <title>{{ $investment->investment_no }}</title>
@endsection

@section('content')
  @php($isDraft = $investment->status === \App\Enums\Finance\CapitalInvestmentStatus::Draft)

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Capital Investment {{ $investment->investment_no }}</h6>
        <span class="badge {{ $investment->status->badgeClass() }} text-white px-12 py-7 radius-4">{{ $investment->status->label() }}</span>
      </div>
      <p class="text-muted m-0">Owner / Partner funding document with finance-journal control</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      @if($isDraft)
        @perm('finance.capital-investments.void', 'edit')
          <button type="button" class="btn btn-outline-danger btn-sm px-14 py-8 radius-8 app-action-button d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#voidInvestmentModal">
            <iconify-icon icon="mdi:cancel"></iconify-icon><span>Void Draft</span>
          </button>
        @endperm
        @perm('finance.capital-investments.post', 'edit')
          <form method="POST" action="{{ route('finance.capital-investments.post', $investment) }}" class="d-flex js-swal-confirm" data-confirm-title="Post this investment?" data-confirm-text="A finance journal Draft will be created. Accountant approval and posting are still required before Cash/Bank balance changes." data-confirm-button="Yes, post investment" data-confirm-color="#0f766e">
            @csrf
            @method('PATCH')
            <button class="btn btn-success btn-sm px-14 py-8 radius-8 text-white app-action-button d-inline-flex align-items-center gap-1">
              <iconify-icon icon="mdi:cash-plus"></iconify-icon><span>Post Investment</span>
            </button>
          </form>
        @endperm
      @endif
      <a href="{{ route('finance.capital-investments.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button d-inline-flex align-items-center gap-1"><iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back</span></a>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h5 class="card-title mb-0">Investment Information</h5></div>
    <div class="card-body row g-3">
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Type</small><strong>{{ $investment->investment_type === 'PARTNER_CAPITAL' ? 'Partner Capital' : 'Owner Capital' }}</strong></div>
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Date</small><strong>{{ $investment->investment_date->format('d M Y') }}</strong></div>
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Branch</small><strong>{{ $investment->branch->name }}</strong></div>
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Amount</small><strong class="text-success-main">{{ number_format($investment->amount, 2) }}</strong></div>
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Cash / Bank Debited</small><strong>{{ $investment->cashBankAccount->code }} — {{ $investment->cashBankAccount->name }}</strong></div>
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Capital Credited</small><strong>{{ $investment->capitalAccount->code }} — {{ $investment->capitalAccount->name }}</strong></div>
      <div class="col-xl-3 col-md-4">
        <small class="text-muted d-block">Finance Journal</small>
        @if($journal)
          <a class="fw-semibold text-info-main" href="{{ route('finance.journals.show', $journal) }}">{{ $journal->voucher_no }} <span class="text-muted">({{ $journal->status->label() }})</span></a>
        @else
          <span class="text-muted">Not created</span>
        @endif
      </div>
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Reference No.</small><strong>{{ $investment->reference_no ?: '—' }}</strong></div>
      @if($investment->note)
        <div class="col-12"><small class="text-muted d-block">Note</small><span>{{ $investment->note }}</span></div>
      @endif
      @if($investment->voided_at)
        <div class="col-12"><small class="text-muted d-block">Void Audit</small><span>{{ $investment->voided_at->format('d M Y, h:i A') }} by {{ $investment->voidedBy?->name ?? 'System' }} — {{ $investment->void_reason }}</span></div>
      @endif
    </div>
  </div>

  @if($isDraft)
    <div class="modal fade" id="voidInvestmentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('finance.capital-investments.void', $investment) }}" class="modal-content">
          @csrf
          @method('PATCH')
          <div class="modal-header"><h5 class="modal-title">Void Draft Investment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body"><p class="text-muted text-sm">This Draft has not affected Cash/Bank or the Account Ledger.</p><label class="form-label">Reason <span class="text-danger">*</span></label><textarea name="void_reason" class="form-control" rows="3" maxlength="1000" required>{{ old('void_reason') }}</textarea></div>
          <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Void Draft</button></div>
        </form>
      </div>
    </div>
  @endif
@endsection
