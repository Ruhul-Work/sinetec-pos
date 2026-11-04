@extends('backend.layouts.master')

@section('meta')
  <title>{{ $payment->payment_no }}</title>
@endsection

@section('content')
  @php
    $isDraft = $payment->status === \App\Enums\Purchase\PurchasePaymentStatus::Draft;
    $isPosted = $payment->status === \App\Enums\Purchase\PurchasePaymentStatus::Posted;
    $statusClass = match ($payment->status) {
      \App\Enums\Purchase\PurchasePaymentStatus::Posted => 'bg-dark-success-gradient',
      \App\Enums\Purchase\PurchasePaymentStatus::Draft => 'bg-dark-warning-gradient',
      default => 'bg-secondary',
    };
    $shownDue = $isPosted ? $remainingDue['due'] : ($isDraft ? max(0, $dueBeforePayment['due'] - (float) $payment->amount) : $remainingDue['due']);
    $dueLabel = $isPosted ? 'Remaining Due' : ($isDraft ? 'Expected Remaining Due' : 'Current Receipt Due');
  @endphp

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Supplier Payment {{ $payment->payment_no }}</h6>
        <span class="badge {{ $statusClass }} text-white px-12 py-7 radius-4">{{ $payment->status->label() }}</span>
      </div>
      <p class="text-muted m-0">Receipt-specific supplier payment and finance-journal control</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      @if($isDraft)
        @perm('purchase.payments.void', 'edit')
          <button type="button" class="btn btn-outline-danger btn-sm px-14 py-8 radius-8 app-action-button" data-bs-toggle="modal" data-bs-target="#voidPaymentModal">
            <iconify-icon icon="mdi:cancel"></iconify-icon><span>Void Draft</span>
          </button>
        @endperm
        @perm('purchase.payments.post', 'edit')
          <form method="POST" action="{{ route('purchase.payments.post', $payment) }}" class="d-flex js-swal-confirm" data-confirm-title="Post this payment?" data-confirm-text="Supplier due will be reduced and a finance journal Draft will be created." data-confirm-button="Yes, post payment" data-confirm-color="#0f766e">
            @csrf @method('PATCH')
            <button class="btn btn-success btn-sm px-14 py-8 radius-8 text-white app-action-button"><iconify-icon icon="mdi:cash-check"></iconify-icon><span>Post Payment</span></button>
          </form>
        @endperm
      @elseif($isPosted)
        @perm('purchase.payments.reverse', 'add')
          <button type="button" class="btn btn-outline-warning btn-sm px-14 py-8 radius-8 app-action-button" data-bs-toggle="modal" data-bs-target="#reversePaymentModal">
            <iconify-icon icon="mdi:source-branch-sync"></iconify-icon><span>Reverse Payment</span>
          </button>
        @endperm
      @endif
      <a href="{{ route('purchase.payments.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back</span></a>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h5 class="card-title mb-0">Payment Information</h5></div>
    <div class="card-body row g-3">
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Supplier</small><strong>{{ $payment->supplier->name }}</strong></div>
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Receipt</small><a class="fw-semibold d-block text-primary-600" href="{{ route('purchase.receipts.show', $payment->receipt) }}">{{ $payment->receipt->receipt_no }}</a></div>
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Payment Date</small><strong>{{ $payment->payment_date->format('d M Y') }}</strong></div>
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Payment Amount</small><strong>{{ number_format($payment->amount, 2) }}</strong></div>
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Payment Account</small><strong>{{ $payment->account->code }} — {{ $payment->account->name }}</strong></div>
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Due Before This Payment</small><strong>{{ number_format($dueBeforePayment['due'], 2) }}</strong></div>
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">{{ $dueLabel }}</small><strong class="text-{{ $shownDue > 0 ? 'danger' : 'success' }}">{{ number_format($shownDue, 2) }}</strong></div>
      <div class="col-xl-3 col-md-4"><small class="text-muted d-block">Finance Journal</small>
        @if($paymentJournal)<a class="fw-semibold text-info-main" href="{{ route('finance.journals.show', $paymentJournal) }}">{{ $paymentJournal->voucher_no }} <span class="text-muted">({{ $paymentJournal->status->label() }})</span></a>@else<span class="text-muted">Not created</span>@endif
      </div>
      @if($payment->reference_no)<div class="col-xl-3 col-md-4"><small class="text-muted d-block">Reference No.</small><strong>{{ $payment->reference_no }}</strong></div>@endif
      @if($payment->note)<div class="col-12"><small class="text-muted d-block">Note</small><span>{{ $payment->note }}</span></div>@endif
      @if($payment->voided_at)<div class="col-12"><small class="text-muted d-block">Void Audit</small><span>{{ $payment->voided_at->format('d M Y, h:i A') }} by {{ $payment->voidedBy?->name ?? 'System' }} — {{ $payment->void_reason }}</span></div>@endif
      @if($payment->reversed_at)<div class="col-12"><small class="text-muted d-block">Reversal Audit</small><span>{{ $payment->reversed_at->format('d M Y, h:i A') }} by {{ $payment->reversedBy?->name ?? 'System' }} — {{ $payment->reversal_reason }}</span>@if($payment->reversalJournal)<br><a class="fw-semibold text-warning-main" href="{{ route('finance.journals.show', $payment->reversalJournal) }}">View reversal journal {{ $payment->reversalJournal->voucher_no }}</a>@endif</div>@endif
    </div>
  </div>

  @if($isDraft)
    <div class="modal fade" id="voidPaymentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered"><form method="POST" action="{{ route('purchase.payments.void', $payment) }}" class="modal-content">
        @csrf @method('PATCH')
        <div class="modal-header"><h5 class="modal-title">Void Draft Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p class="text-muted text-sm">This Draft has not affected supplier due, Cash/Bank or the Account Ledger.</p><label class="form-label">Reason <span class="text-danger">*</span></label><textarea name="void_reason" class="form-control" rows="3" maxlength="1000" required>{{ old('void_reason') }}</textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Void Draft</button></div>
      </form></div>
    </div>
  @endif

  @if($isPosted)
    <div class="modal fade" id="reversePaymentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered"><form method="POST" action="{{ route('purchase.payments.reverse', $payment) }}" class="modal-content">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Reverse Posted Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p class="text-muted text-sm">Supplier due will be restored. If the linked journal is already posted, a separate reversal Draft will be created for accountant approval.</p><div class="mb-3"><label class="form-label">Reversal Date <span class="text-danger">*</span></label><input type="date" name="reversal_date" class="form-control" value="{{ old('reversal_date', now()->toDateString()) }}" required></div><label class="form-label">Reason <span class="text-danger">*</span></label><textarea name="reversal_reason" class="form-control" rows="3" maxlength="1000" required>{{ old('reversal_reason') }}</textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning">Reverse Payment</button></div>
      </form></div>
    </div>
  @endif
@endsection
