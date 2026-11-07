@extends('backend.layouts.master')

@section('meta')
  <title>Trial Balance</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Trial Balance</h6>
        <x-backend.page-note title="How to read this report" label="View report rule" align="start">
          Only Posted opening balances and Posted journals are included. Draft and Void documents do not affect this report.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Account-wise debit and credit balance as of the selected date</p>
    </div>
    <ul class="d-flex align-items-center gap-2 mb-0">
      <li class="fw-medium"><a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary"><iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard</a></li>
      <li>-</li>
      <li class="fw-medium">Trial Balance</li>
    </ul>
  </div>

  <div class="card mb-24">
    <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <h5 class="card-title mb-0">Report Filters</h5>
      <button type="button" id="resetTrialBalanceFilters" class="btn btn-outline-secondary btn-sm px-12 py-8 radius-8 app-action-button">
        <iconify-icon icon="mdi:filter-remove-outline"></iconify-icon><span>Reset Filters</span>
      </button>
    </div>
    <div class="card-body">
      <div class="row gy-3 align-items-end" id="trialBalanceFilters">
        <div class="col-xl-5 col-md-6">
          <label class="form-label text-sm">Branch</label>
          <select id="tbBranch" class="form-control js-s2">
            <option value="">All available branches</option>
            @foreach ($branches as $branch)
              <option value="{{ $branch->id }}">{{ $branch->name }} ({{ $branch->code }})</option>
            @endforeach
          </select>
        </div>
        <div class="col-xl-4 col-md-4">
          <label class="form-label text-sm">As of Date</label>
          <input type="date" id="tbDate" class="form-control" value="{{ now()->toDateString() }}">
        </div>
        <div class="col-xl-3 col-md-2">
          <button type="button" id="tbApply" class="btn btn-primary w-100 px-14 py-10 radius-8 app-action-button app-primary-action">
            <iconify-icon icon="mdi:magnify"></iconify-icon><span>Apply</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <div id="trialBalanceSummary" class="row g-3 mb-24">
    <div class="col-xl-4 col-md-6">
      <div class="card h-100 account-ledger-summary-card account-ledger-summary-card--debit">
        <div class="card-body py-16">
          <small class="text-muted d-block mb-6">Total Debit</small>
          <h6 id="tbDebit" class="mb-0 fw-bold text-success-main">&mdash;</h6>
          <small class="text-muted">Selected report scope</small>
        </div>
      </div>
    </div>
    <div class="col-xl-4 col-md-6">
      <div class="card h-100 account-ledger-summary-card account-ledger-summary-card--credit">
        <div class="card-body py-16">
          <small class="text-muted d-block mb-6">Total Credit</small>
          <h6 id="tbCredit" class="mb-0 fw-bold text-danger-main">&mdash;</h6>
          <small class="text-muted">Selected report scope</small>
        </div>
      </div>
    </div>
    <div class="col-xl-4 col-md-12">
      <div class="card h-100 account-ledger-summary-card account-ledger-summary-card--closing">
        <div class="card-body py-16">
          <small class="text-muted d-block mb-6">Report Status</small>
          <h6 id="tbBalanceStatus" class="mb-0 fw-bold text-muted d-flex align-items-center gap-1"><iconify-icon icon="mdi:scale-balance"></iconify-icon><span>Loading...</span></h6>
          <small id="tbBalanceNote" class="text-muted">Checking debit and credit totals</small>
        </div>
      </div>
    </div>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">Posted Account Balances</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">@include('backend.include.buttons')</ul>
      </div>
    </div>
    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="trialBalanceTable" data-has-action-column="false" style="width:100%">
        <thead>
          <tr>
            <th style="width:65px">S.L</th>
            <th>Account</th>
            <th>Type</th>
            <th class="text-end">Debit Movement</th>
            <th class="text-end">Credit Movement</th>
            <th class="text-end">Closing Dr</th>
            <th class="text-end">Closing Cr</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
  <script>
    var DATATABLE_URL = @json(route('finance.trial-balance.list.ajax'));

    (function($) {
      const summaryUrl = @json(route('finance.trial-balance.summary'));
      const defaultDate = @json(now()->toDateString());
      S2.auto($('#trialBalanceFilters'));

      function requestData() {
        return {branch_id: $('#tbBranch').val(), date_to: $('#tbDate').val()};
      }

      function loadSummary() {
        $.post(summaryUrl, requestData()).done(function(data) {
          $('#tbDebit').text(data.debit);
          $('#tbCredit').text(data.credit);

          const $status = $('#tbBalanceStatus');
          if (data.balanced) {
            $status.attr('class', 'mb-0 fw-bold text-success-main d-flex align-items-center gap-1')
              .html('<iconify-icon icon="mdi:check-decagram-outline"></iconify-icon><span>Balanced</span>');
            $('#tbBalanceNote').text('Debit and credit totals are equal');
          } else {
            $status.attr('class', 'mb-0 fw-bold text-danger-main d-flex align-items-center gap-1')
              .html('<iconify-icon icon="mdi:alert-circle-outline"></iconify-icon><span>Not Balanced</span>');
            $('#tbBalanceNote').text('Difference: ' + data.difference);
          }
        });
      }

      function refreshReport() {
        if ($.fn.DataTable.isDataTable('#trialBalanceTable')) {
          $('#trialBalanceTable').DataTable().ajax.reload();
        }
        loadSummary();
      }

      $(document).on('preXhr.dt', '#trialBalanceTable', function(event, settings, data) {
        Object.assign(data, requestData());
      });

      $('#tbApply, #tbDate').on('click change', refreshReport);
      $('#tbBranch').on('change', refreshReport);
      $('#resetTrialBalanceFilters').on('click', function() {
        $('#tbBranch').val('').trigger('change.select2');
        $('#tbDate').val(defaultDate);
        refreshReport();
      });

      $(loadSummary);
    })(jQuery);
  </script>
@endsection
