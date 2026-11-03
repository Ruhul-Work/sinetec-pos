@extends('backend.layouts.master')

@section('meta')
  <title>{{ $journal->voucher_no }}</title>
@endsection

@section('content')
  @php
    $balanced = abs($totalDebit - $totalCredit) < 0.00005;
    $isDraft = $journal->status === \App\Enums\Finance\JournalStatus::Draft;
    $isApproved = $isDraft && $journal->approved_at !== null;
    $isPosted = $journal->status === \App\Enums\Finance\JournalStatus::Posted;
  @endphp

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <h6 class="fw-semibold mb-0">Journal {{ $journal->voucher_no }}</h6>
        <span class="badge text-sm fw-semibold {{ $journal->status->badgeClass() }} px-12 py-7 radius-4 text-white">{{ $journal->status->label() }}</span>
        @if($isApproved)<span class="badge bg-info-focus text-info-main px-12 py-7 radius-4">Approved · Ready to Post</span>@endif
        <x-backend.page-note title="Journal workflow" label="View workflow rules" align="start">
          A balanced Draft must be approved before posting. Posted journals affect account balances and cannot be voided; create a reversal draft instead. Void is available only while a journal remains Draft.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Account-wise debit and credit details</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      @if($isDraft)
        @if(!$isApproved)
          @perm('finance.journals.approve', 'edit')
            <form method="POST" action="{{ route('finance.journals.approve', $journal) }}" class="d-flex js-swal-confirm" data-confirm-title="Approve this journal?" data-confirm-text="The journal will become ready for posting after the balance check passes." data-confirm-button="Yes, approve" data-confirm-color="#0f766e">
              @csrf @method('PATCH')
              <button class="btn btn-info btn-sm px-14 py-8 radius-8 text-white app-action-button"><iconify-icon icon="mdi:check-decagram-outline"></iconify-icon><span>Approve</span></button>
            </form>
          @endperm
        @else
          @perm('finance.journals.post', 'edit')
            <form method="POST" action="{{ route('finance.journals.post', $journal) }}" class="d-flex js-swal-confirm" data-confirm-title="Post this journal?" data-confirm-text="Posted journal amounts will affect account balances. Corrections require a separate reversal journal." data-confirm-button="Yes, post journal" data-confirm-color="#198754">
              @csrf @method('PATCH')
              <button class="btn btn-success btn-sm px-14 py-8 radius-8 text-white app-action-button"><iconify-icon icon="mdi:book-check-outline"></iconify-icon><span>Post Journal</span></button>
            </form>
          @endperm
        @endif
        @perm('finance.journals.void', 'edit')
          <button type="button" class="btn btn-outline-danger btn-sm px-14 py-8 radius-8 app-action-button" data-bs-toggle="modal" data-bs-target="#voidJournalModal"><iconify-icon icon="mdi:cancel"></iconify-icon><span>Void Draft</span></button>
        @endperm
      @elseif($isPosted)
        @if($journal->reversal)
          <a href="{{ route('finance.journals.show', $journal->reversal) }}" class="btn btn-outline-warning btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:source-branch-sync"></iconify-icon><span>View Reversal</span></a>
        @else
          @perm('finance.journals.reverse', 'add')
            <button type="button" class="btn btn-outline-warning btn-sm px-14 py-8 radius-8 app-action-button" data-bs-toggle="modal" data-bs-target="#reverseJournalModal"><iconify-icon icon="mdi:source-branch-sync"></iconify-icon><span>Create Reversal</span></button>
          @endperm
        @endif
      @endif
      @if($source['url'])
        <a href="{{ $source['url'] }}" class="btn btn-outline-info btn-sm px-14 py-8 radius-8 app-action-button">
          <iconify-icon icon="mdi:open-in-new"></iconify-icon><span>View Source</span>
        </a>
      @endif
      <a href="{{ route('finance.journals.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button">
        <iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back to List</span>
      </a>
    </div>
  </div>

  <div class="card mb-24">
    <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <h5 class="card-title mb-0">Journal Information</h5>
      <span class="badge d-inline-flex align-items-center gap-1 {{ $balanced ? 'bg-success-focus text-success-main' : 'bg-danger-focus text-danger-main' }} px-12 py-7 radius-4">
        <iconify-icon icon="{{ $balanced ? 'mdi:check-decagram-outline' : 'mdi:alert-circle-outline' }}" class="d-inline-flex align-items-center"></iconify-icon>
        <span>{{ $balanced ? 'Balanced' : 'Out of Balance' }}</span>
      </span>
    </div>
    <div class="card-body">
      <div class="row gy-3">
        <div class="col-xl-3 col-md-4 col-sm-6"><small class="text-muted d-block">Entry Date</small><strong>{{ $journal->entry_date->format('d M Y') }}</strong></div>
        <div class="col-xl-3 col-md-4 col-sm-6"><small class="text-muted d-block">Branch</small><strong>{{ $journal->branch->name }}</strong><br><code>{{ $journal->branch->code }}</code></div>
        <div class="col-xl-3 col-md-4 col-sm-6"><small class="text-muted d-block">Voucher Type</small><strong>{{ $journal->voucherType->name }}</strong><br><code>{{ $journal->voucherType->code }}</code></div>
        <div class="col-xl-3 col-md-4 col-sm-6"><small class="text-muted d-block">Fiscal Year</small><strong>{{ $journal->fiscalYear->name }}</strong></div>
        <div class="col-xl-3 col-md-4 col-sm-6">
          <small class="text-muted d-block">Source</small>
          @if($source['url'])
            <a href="{{ $source['url'] }}" class="fw-semibold text-info-main">{{ $source['label'] }} · {{ $source['number'] }}</a>
          @else
            <strong>{{ $source['label'] }} · {{ $source['number'] }}</strong>
          @endif
        </div>
        <div class="col-xl-3 col-md-4 col-sm-6"><small class="text-muted d-block">Created</small><strong>{{ $journal->created_at->format('d M Y, h:i A') }}</strong><br><span>{{ $journal->createdBy?->name ?? 'System' }}</span></div>
        <div class="col-xl-3 col-md-4 col-sm-6"><small class="text-muted d-block">Posting Key</small><code class="text-wrap">{{ $journal->posting_key }}</code></div>
        @if($journal->approved_at)
          <div class="col-xl-3 col-md-4 col-sm-6"><small class="text-muted d-block">Approved</small><strong>{{ $journal->approved_at->format('d M Y, h:i A') }}</strong><br><span>{{ $journal->approvedBy?->name ?? 'System' }}</span></div>
        @endif
        @if($journal->posted_at)
          <div class="col-xl-3 col-md-4 col-sm-6"><small class="text-muted d-block">Posted</small><strong>{{ $journal->posted_at->format('d M Y, h:i A') }}</strong><br><span>{{ $journal->postedBy?->name ?? 'System' }}</span></div>
        @endif
        @if($journal->voided_at)
          <div class="col-xl-3 col-md-4 col-sm-6"><small class="text-muted d-block">Voided</small><strong>{{ $journal->voided_at->format('d M Y, h:i A') }}</strong><br><span>{{ $journal->voidedBy?->name ?? 'System' }}</span></div>
          <div class="col-12"><small class="text-muted d-block">Void Reason</small><span>{{ $journal->void_reason }}</span></div>
        @endif
        @if($journal->reversalOf)
          <div class="col-xl-3 col-md-4 col-sm-6"><small class="text-muted d-block">Reversal Of</small><a href="{{ route('finance.journals.show', $journal->reversalOf) }}" class="fw-semibold text-warning-main">{{ $journal->reversalOf->voucher_no }}</a></div>
        @endif
        @if($journal->reversal)
          <div class="col-xl-3 col-md-4 col-sm-6"><small class="text-muted d-block">Reversal Journal</small><a href="{{ route('finance.journals.show', $journal->reversal) }}" class="fw-semibold text-warning-main">{{ $journal->reversal->voucher_no }}</a></div>
        @endif
        @if($journal->narration)
          <div class="col-12"><small class="text-muted d-block">Narration</small><span>{{ $journal->narration }}</span></div>
        @endif
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h5 class="card-title mb-0">Debit / Credit Lines</h5></div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table bordered-table align-middle mb-0">
          <thead>
            <tr><th>#</th><th>Account Code</th><th>Account</th><th>Type</th><th>Memo</th><th class="text-end">Debit (BDT)</th><th class="text-end">Credit (BDT)</th></tr>
          </thead>
          <tbody>
            @foreach($journal->lines as $index => $line)
              <tr>
                <td>{{ $index + 1 }}</td>
                <td><code>{{ $line->account->code }}</code></td>
                <td><strong>{{ $line->account->name }}</strong></td>
                <td><span class="badge bg-light text-dark">{{ $line->account->type->name }}</span></td>
                <td>{{ $line->memo ?: '-' }}</td>
                <td class="text-end fw-semibold">{{ number_format((float) $line->debit, 2) }}</td>
                <td class="text-end fw-semibold">{{ number_format((float) $line->credit, 2) }}</td>
              </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr class="bg-base"><th colspan="5" class="text-end">Total</th><th class="text-end">{{ number_format($totalDebit, 2) }}</th><th class="text-end">{{ number_format($totalCredit, 2) }}</th></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  @if($isDraft)
    <div class="modal fade" id="voidJournalModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered"><form method="POST" action="{{ route('finance.journals.void', $journal) }}" class="modal-content">
        @csrf @method('PATCH')
        <div class="modal-header"><h5 class="modal-title">Void Draft Journal</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p class="text-muted text-sm">This draft will no longer be postable and will not affect balances.</p><label class="form-label">Reason <span class="text-danger">*</span></label><textarea name="void_reason" class="form-control" rows="3" maxlength="1000" required>{{ old('void_reason') }}</textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm app-action-button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger btn-sm app-action-button"><iconify-icon icon="mdi:cancel"></iconify-icon><span>Void Draft</span></button></div>
      </form></div>
    </div>
  @endif

  @if($isPosted && !$journal->reversal)
    <div class="modal fade" id="reverseJournalModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered"><form method="POST" action="{{ route('finance.journals.reverse', $journal) }}" class="modal-content">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Create Reversal Draft</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p class="text-muted text-sm">A new Draft journal with swapped debit/credit lines will be created. It must be approved and posted separately.</p><div class="mb-3"><label class="form-label">Reversal Date <span class="text-danger">*</span></label><input type="date" name="reversal_date" class="form-control" value="{{ old('reversal_date', now()->toDateString()) }}" required></div><label class="form-label">Reason <span class="text-danger">*</span></label><textarea name="reversal_reason" class="form-control" rows="3" maxlength="1000" required>{{ old('reversal_reason') }}</textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm app-action-button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning btn-sm app-action-button"><iconify-icon icon="mdi:source-branch-sync"></iconify-icon><span>Create Reversal</span></button></div>
      </form></div>
    </div>
  @endif
@endsection
