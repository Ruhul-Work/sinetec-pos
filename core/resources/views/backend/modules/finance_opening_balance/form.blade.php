@extends('backend.layouts.master')

@section('meta')
  <title>{{ $readonly ? 'View' : ($lines->isEmpty() ? 'New' : 'Edit') }} Finance Opening Balance</title>
@endsection

@section('content')
  @php
    $isExistingDraft = $lines->isNotEmpty() && $status === 'DRAFT';
    $formLines = old('lines', $lines->map(fn ($line) => ['account_id' => $line->account_id, 'debit' => $line->debit, 'credit' => $line->credit])->all());
    if (count($formLines) < 2) $formLines = array_pad($formLines, 2, ['account_id' => '', 'debit' => '', 'credit' => '']);
  @endphp
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">{{ $readonly ? 'Posted' : ($isExistingDraft ? 'Edit' : 'New') }} Finance Opening Balance</h6>
        <span class="badge {{ $status === 'POSTED' ? 'bg-dark-success-gradient' : 'bg-dark-warning-gradient' }} text-white px-12 py-7 radius-4">{{ $status === 'POSTED' ? 'Posted' : 'Draft' }}</span>
        <x-backend.page-note title="Balance before posting" label="View balance rule" align="start">Cash, Bank, Inventory and Receivables normally start on Debit. Capital, Loans and Supplier Payable normally start on Credit. This opening set must be balanced before posting.</x-backend.page-note>
      </div>
      <p class="text-muted m-0">One controlled opening set for the selected Branch and Fiscal Year</p>
    </div>
    <a href="{{ route('finance.opening-balances.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back to List</span></a>
  </div>

  <form method="POST" action="{{ $isExistingDraft ? route('finance.opening-balances.update', ['fiscalYear' => $fiscalYearId, 'branch' => $branchId]) : route('finance.opening-balances.store') }}" id="openingBalanceForm">
    @csrf
    @if($isExistingDraft) @method('PUT') @endif
    <div class="card mb-24">
      <div class="card-header"><h5 class="card-title mb-0">Opening Scope</h5></div>
      <div class="card-body row g-3">
        <div class="col-md-6"><label class="form-label">Fiscal Year <span class="text-danger">*</span></label>
          <select name="fiscal_year_id" class="form-select js-s2" {{ ($isExistingDraft || $readonly) ? 'disabled' : '' }} required>
            @foreach($fiscalYears as $year)<option value="{{ $year->id }}" @selected((int) $fiscalYearId === (int) $year->id)>{{ $year->name }} ({{ \Carbon\Carbon::parse($year->start_date)->format('d M Y') }} – {{ \Carbon\Carbon::parse($year->end_date)->format('d M Y') }})</option>@endforeach
          </select>
          @if($isExistingDraft || $readonly)<input type="hidden" name="fiscal_year_id" value="{{ $fiscalYearId }}">@endif
        </div>
        <div class="col-md-6"><label class="form-label">Branch <span class="text-danger">*</span></label>
          <select name="branch_id" class="form-select js-s2" {{ ($isExistingDraft || $readonly) ? 'disabled' : '' }} required>
            @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int) $branchId === (int) $branch->id)>{{ $branch->name }} — {{ $branch->code }}</option>@endforeach
          </select>
          @if($isExistingDraft || $readonly)<input type="hidden" name="branch_id" value="{{ $branchId }}">@endif
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap"><div><h5 class="card-title mb-0">Opening Account Lines</h5><small class="text-muted">Choose branch-assigned BDT accounts only. Enter a Debit or Credit value, never both.</small></div>@if(! $readonly)<button type="button" class="btn btn-outline-primary btn-sm px-14 py-8 radius-8 app-action-button" id="addOpeningLine"><iconify-icon icon="mdi:plus"></iconify-icon><span>Add Account</span></button>@endif</div>
      <div class="card-body">
        <div class="table-responsive"><table class="table bordered-table align-middle" id="openingLinesTable"><thead><tr><th>Account</th><th class="text-end" style="width:22%">Debit</th><th class="text-end" style="width:22%">Credit</th>@if(! $readonly)<th style="width:52px"></th>@endif</tr></thead><tbody id="openingLinesBody">
          @foreach($formLines as $index => $line)
            <tr class="opening-line">
              <td><select name="lines[{{ $index }}][account_id]" class="form-select form-select-sm js-s2 opening-account" {{ $readonly ? 'disabled' : '' }} required><option value="">Select account</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((int) ($line['account_id'] ?? 0) === (int) $account->id)>{{ $account->code }} — {{ $account->name }} ({{ $account->normal_balance }})</option>@endforeach</select>@if($readonly)<input type="hidden" name="lines[{{ $index }}][account_id]" value="{{ $line['account_id'] }}">@endif</td>
              <td><input type="number" name="lines[{{ $index }}][debit]" class="form-control form-control-sm text-end opening-debit" min="0" step="0.0001" value="{{ $line['debit'] ?? '' }}" {{ $readonly ? 'readonly' : '' }}></td>
              <td><input type="number" name="lines[{{ $index }}][credit]" class="form-control form-control-sm text-end opening-credit" min="0" step="0.0001" value="{{ $line['credit'] ?? '' }}" {{ $readonly ? 'readonly' : '' }}></td>
              @if(! $readonly)<td class="text-center"><button type="button" class="border-0 w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main remove-opening-line" title="Remove"><iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></button></td>@endif
            </tr>
          @endforeach
        </tbody><tfoot><tr><th class="text-end">Total</th><th class="text-end"><span id="openingDebitTotal">0.00</span></th><th class="text-end"><span id="openingCreditTotal">0.00</span></th>@if(! $readonly)<th></th>@endif</tr><tr><th colspan="{{ $readonly ? 3 : 4 }}" class="text-end"><span class="text-muted me-2">Difference:</span><span id="openingDifference" class="fw-semibold">0.00</span></th></tr></tfoot></table></div>
        @if(! $readonly)<div class="d-flex flex-wrap justify-content-end gap-2 mt-20">@if($isExistingDraft)<a href="{{ route('finance.opening-balances.show', ['fiscalYear' => $fiscalYearId, 'branch' => $branchId]) }}" class="btn btn-outline-secondary px-16 py-9 radius-8 app-action-button">Cancel</a>@else<a href="{{ route('finance.opening-balances.index') }}" class="btn btn-outline-secondary px-16 py-9 radius-8 app-action-button">Cancel</a>@endif<button class="btn btn-primary app-primary-action px-18 py-9 radius-8">Save Draft</button></div>@endif
      </div>
    </div>
  </form>

  @if($isExistingDraft && ! $readonly)
    <form method="POST" action="{{ route('finance.opening-balances.post', ['fiscalYear' => $fiscalYearId, 'branch' => $branchId]) }}" class="d-flex justify-content-end mt-16 js-swal-confirm" data-confirm-title="Post opening balance?" data-confirm-text="Posted opening balances will affect Account Ledger and cannot be edited." data-confirm-button="Yes, post opening" data-confirm-color="#0f766e">@csrf @method('PATCH')<button class="btn btn-success px-18 py-9 radius-8 app-action-button"><iconify-icon icon="mdi:book-check-outline"></iconify-icon><span>Post Opening Balance</span></button></form>
  @endif

  @if(! $readonly)
    <template id="openingLineTemplate"><tr class="opening-line"><td><select name="lines[__INDEX__][account_id]" class="form-select form-select-sm js-s2 opening-account" required><option value="">Select account</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }} ({{ $account->normal_balance }})</option>@endforeach</select></td><td><input type="number" name="lines[__INDEX__][debit]" class="form-control form-control-sm text-end opening-debit" min="0" step="0.0001"></td><td><input type="number" name="lines[__INDEX__][credit]" class="form-control form-control-sm text-end opening-credit" min="0" step="0.0001"></td><td class="text-center"><button type="button" class="border-0 w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main remove-opening-line" title="Remove"><iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></button></td></tr></template>
  @endif
@endsection

@section('script')
  <script>
    (function () {
      let nextIndex = {{ count($formLines) }};
      const total = () => {
        let debit = 0, credit = 0;
        $('#openingLinesBody .opening-debit').each(function () { debit += parseFloat($(this).val()) || 0; });
        $('#openingLinesBody .opening-credit').each(function () { credit += parseFloat($(this).val()) || 0; });
        const difference = debit - credit;
        $('#openingDebitTotal').text(debit.toFixed(2));
        $('#openingCreditTotal').text(credit.toFixed(2));
        $('#openingBalanceForm').data('opening-debit', debit).data('opening-credit', credit);
        $('#openingDifference').text(Math.abs(difference).toFixed(2) + (difference === 0 ? ' Balanced' : (difference > 0 ? ' Dr difference' : ' Cr difference'))).toggleClass('text-danger', Math.abs(difference) > 0.00005).toggleClass('text-success-main', Math.abs(difference) <= 0.00005);
      };
      $('#addOpeningLine').on('click', function () { const row = $($('#openingLineTemplate').html().replaceAll('__INDEX__', nextIndex++)); $('#openingLinesBody').append(row); window.S2 && S2.auto(row); total(); });
      $(document).on('click', '.remove-opening-line', function () { if ($('#openingLinesBody .opening-line').length <= 2) return; $(this).closest('tr').remove(); total(); });
      $(document).on('input', '.opening-debit, .opening-credit', total);
      $('#openingBalanceForm').on('submit', function (event) { total(); const debit = $(this).data('opening-debit') || 0, credit = $(this).data('opening-credit') || 0; if (Math.abs(debit - credit) > 0.00005) { event.preventDefault(); window.Swal ? Swal.fire({icon: 'error', title: 'Opening Balance is not balanced', text: 'Total Debit and Credit must be equal before saving.'}) : alert('Total Debit and Credit must be equal before saving.'); } });
      total();
    })();
  </script>
@endsection
