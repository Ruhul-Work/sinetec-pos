@extends('backend.layouts.master')

@section('meta')
    <title>
        Fiscal Year Closing</title>
@endsection

@section('content')
    @php($isDraft = $closing->status === \App\Enums\Finance\FiscalYearClosingStatus::Draft)
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Fiscal Year Closing {{ $closing->closing_no }}</h6><span
                    class="badge {{ $closing->status->badgeClass() }} text-white px-12 py-7 radius-4">{{ $closing->status->label() }}</span>
            </div>
            <p class="text-muted m-0">Year-end Drawings to Capital adjustment</p>
        </div>
        <div class="d-flex gap-2">
            @if ($isDraft)
                @perm('finance.fiscal-year-closings.post', 'edit')
                    <form method="POST" action="{{ route('finance.fiscal-year-closings.post', $closing) }}"
                        class="js-swal-confirm" data-confirm-title="Prepare this fiscal year closing?"
                        data-confirm-text="A balanced Closing Journal Draft will be created. All existing Draft journals for this fiscal year and branch must be resolved first."
                        data-confirm-button="Yes, prepare closing">@csrf @method('PATCH')<button
                            class="btn btn-success btn-sm text-white d-inline-flex align-items-center gap-1"><iconify-icon
                                icon="mdi:calendar-check-outline"></iconify-icon><span>Prepare Closing</span></button></form>
                @endperm
                @perm('finance.fiscal-year-closings.void', 'edit')
                    <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#voidModal">Void
                        Draft</button>
                @endperm
            @endif
            <a href="{{ route('finance.fiscal-year-closings.index') }}" class="btn btn-outline-secondary btn-sm">Back</a>
        </div>
    </div>
    <div class="card mb-24">
        <div class="card-header">
            <h5 class="card-title mb-0">Closing Information</h5>
        </div>
        <div class="card-body row g-3">
            <div class="col-md-3"><small class="text-muted d-block">Fiscal
                    Year</small><strong>{{ $closing->fiscalYear->name }}</strong></div>
            <div class="col-md-3"><small class="text-muted d-block">Closing
                    Date</small><strong>{{ $closing->closing_date->format('d M Y') }}</strong></div>
            <div class="col-md-3"><small
                    class="text-muted d-block">Branch</small><strong>{{ $closing->branch->name }}</strong></div>
            <div class="col-md-3"><small class="text-muted d-block">Amount</small><strong
                    class="text-primary-600">{{ number_format($closing->amount, 2) }}</strong></div>
            <div class="col-md-4"><small class="text-muted d-block">Drawings
                    Credited</small><strong>{{ $closing->drawingsAccount->code }} —
                    {{ $closing->drawingsAccount->name }}</strong></div>
            <div class="col-md-4"><small class="text-muted d-block">Capital
                    Debited</small><strong>{{ $closing->capitalAccount->code }} —
                    {{ $closing->capitalAccount->name }}</strong></div>
            <div class="col-md-4"><small class="text-muted d-block">Closing Journal</small>
                @if ($journal)
                    <a class="fw-semibold text-info-main"
                        href="{{ route('finance.journals.show', $journal) }}">{{ $journal->voucher_no }}
                    ({{ $journal->status->label() }})</a>@else<span class="text-muted">Not created</span>
                @endif
            </div>
            @if ($closing->reference_no)
                <div class="col-md-6"><small class="text-muted d-block">Reference No.</small>{{ $closing->reference_no }}
                </div>
            @endif
            @if ($closing->note)
                    <div class="col-md-6"><small class="text-muted d-block">Note</small>{{ $closing->note }}</div>
            @endif
        </div>
    </div>
    @if ($isDraft)
        <div class="modal fade" id="voidModal" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('finance.fiscal-year-closings.void', $closing) }}"
                    class="modal-content">@csrf @method('PATCH')<div class="modal-header">
                        <h5 class="modal-title">Void Draft Closing</h5><button type="button" class="btn-close"
                            data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body"><label class="form-label">Reason *</label>
                        <textarea name="void_reason" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary"
                            data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Void Draft</button></div>
                </form>
            </div>
        </div>
    @endif
@endsection
