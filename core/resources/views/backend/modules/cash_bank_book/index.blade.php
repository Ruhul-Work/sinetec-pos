@extends('backend.layouts.master')

@section('meta')
  <title>Cash / Bank Book</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Cash / Bank Book</h6>
        <x-backend.page-note title="How to read this book" label="View Cash/Bank rule" align="start">Only Posted opening balances and Posted finance journals appear here. Money In is Debit and Money Out is Credit for the selected Cash/Bank account. Draft journals do not affect this book.</x-backend.page-note>
      </div>
      <p class="text-muted m-0">Cash and Bank movement with true branch-wise running balance</p>
    </div>
  </div>

  <div class="card mb-24">
    <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap"><h5 class="card-title mb-0">Book Filters</h5><button type="button" id="resetCashBookFilters" class="btn btn-outline-secondary btn-sm px-12 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:filter-remove-outline"></iconify-icon><span>Reset Filters</span></button></div>
    <div class="card-body"><div class="row gy-3 align-items-end" id="cashBookFilters">
      <div class="col-xl-4 col-md-6"><label class="form-label text-sm">Cash / Bank Account <span class="text-danger">*</span></label><select id="cashBookAccount" class="form-control" data-placeholder="Select Cash / Bank account"></select></div>
      <div class="col-xl-3 col-md-6"><label class="form-label text-sm">Branch</label><select id="cashBookBranch" class="form-control js-s2"><option value="">All available branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }} ({{ $branch->code }})</option>@endforeach</select></div>
      <div class="col-xl-2 col-md-4"><label class="form-label text-sm">From Date</label><input type="date" id="cashBookDateFrom" class="form-control" value="{{ now()->startOfMonth()->format('Y-m-d') }}"></div>
      <div class="col-xl-2 col-md-4"><label class="form-label text-sm">To Date</label><input type="date" id="cashBookDateTo" class="form-control" value="{{ now()->format('Y-m-d') }}"></div>
      <div class="col-xl-1 col-md-4"><button type="button" id="applyCashBookFilters" class="btn btn-primary w-100 px-14 py-10 radius-8 app-action-button app-primary-action"><iconify-icon icon="mdi:magnify"></iconify-icon><span>Apply</span></button></div>
    </div></div>
  </div>

  <div id="cashBookSummary" class="row g-3 mb-24 d-none">
    <div class="col-xl-3 col-md-6"><div class="card h-100 account-ledger-summary-card account-ledger-summary-card--opening"><div class="card-body py-16"><small class="text-muted d-block mb-6">Opening Balance</small><h6 class="mb-0 fw-bold" data-summary="opening">—</h6><small class="text-muted">Before selected period</small></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="card h-100 account-ledger-summary-card account-ledger-summary-card--debit"><div class="card-body py-16"><small class="text-muted d-block mb-6">Money In</small><h6 class="mb-0 fw-bold text-success-main" data-summary="money-in">—</h6><small class="text-muted">Debit in selected period</small></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="card h-100 account-ledger-summary-card account-ledger-summary-card--credit"><div class="card-body py-16"><small class="text-muted d-block mb-6">Money Out</small><h6 class="mb-0 fw-bold text-danger-main" data-summary="money-out">—</h6><small class="text-muted">Credit in selected period</small></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="card h-100 account-ledger-summary-card account-ledger-summary-card--closing"><div class="card-body py-16"><small class="text-muted d-block mb-6">Closing Balance</small><h6 class="mb-0 fw-bold" data-summary="closing">—</h6><small class="text-muted">As of selected end date</small></div></div></div>
  </div>

  <div class="card basic-data-table"><div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap"><h5 class="card-title mb-0">Posted Cash / Bank Movements</h5><div class="actions-bar d-flex align-items-center gap-2 flex-wrap"><div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div><ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">@include('backend.include.buttons')</ul></div></div><div class="card-body"><table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="cashBookTable" style="width:100%"><thead><tr><th style="width:65px">S.L</th><th>Date</th><th>Account</th><th>Branch</th><th>Voucher / Source</th><th>Memo</th><th class="text-end">Money In</th><th class="text-end">Money Out</th><th class="text-end">Running Balance</th><th style="width:80px">Journal</th></tr></thead><tbody></tbody></table></div></div>
@endsection

@section('script')
  <script>
    var DATATABLE_URL = @json(route('finance.cash-bank-book.list.ajax'));
    (function($) {
      const accountUrl = @json(route('finance.cash-bank-book.accounts.select2'));
      const summaryUrl = @json(route('finance.cash-bank-book.summary'));
      const defaultDateFrom = @json(now()->startOfMonth()->format('Y-m-d'));
      const defaultDateTo = @json(now()->format('Y-m-d'));
      S2.auto($('#cashBookFilters'));
      S2.init($('#cashBookAccount'), {ajax: {url: accountUrl, dataType: 'json', delay: 250, data: params => ({q: params.term || '', branch_id: $('#cashBookBranch').val()}), processResults: data => ({results: data.results || []})}});
      function requestData() { return {account_id: $('#cashBookAccount').val(), branch_id: $('#cashBookBranch').val(), date_from: $('#cashBookDateFrom').val(), date_to: $('#cashBookDateTo').val()}; }
      function reloadBook() { if ($.fn.DataTable.isDataTable('#cashBookTable')) $('#cashBookTable').DataTable().ajax.reload(); }
      function reloadSummary() { if (!$('#cashBookAccount').val()) return $('#cashBookSummary').addClass('d-none'); $.post(summaryUrl, requestData()).done(function(data) { if (!data.selected) return $('#cashBookSummary').addClass('d-none'); $('[data-summary="opening"]').attr('class', 'mb-0 fw-bold ' + data.opening.class).text((data.opening.side ? data.opening.side + ' ' : '') + data.opening.amount); $('[data-summary="money-in"]').text(data.money_in); $('[data-summary="money-out"]').text(data.money_out); $('[data-summary="closing"]').attr('class', 'mb-0 fw-bold ' + data.closing.class).text((data.closing.side ? data.closing.side + ' ' : '') + data.closing.amount); $('#cashBookSummary').removeClass('d-none'); }); }
      function refreshBook() { reloadBook(); reloadSummary(); }
      $(document).on('preXhr.dt', '#cashBookTable', function(event, settings, data) { Object.assign(data, requestData()); });
      $('#cashBookAccount').on('change', refreshBook);
      $('#cashBookBranch').on('change', function() { $('#cashBookAccount').empty().trigger('change'); refreshBook(); });
      $('#applyCashBookFilters, #cashBookDateFrom, #cashBookDateTo').on('click change', refreshBook);
      $('#resetCashBookFilters').on('click', function() { $('#cashBookBranch').val('').trigger('change'); $('#cashBookAccount').empty().trigger('change'); $('#cashBookDateFrom').val(defaultDateFrom); $('#cashBookDateTo').val(defaultDateTo); refreshBook(); });
    })(jQuery);
  </script>
@endsection
