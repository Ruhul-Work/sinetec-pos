@extends('backend.layouts.master')

@section('meta')
  <title>Finance Opening Balance</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Finance Opening Balance</h6>
        <x-backend.page-note title="Opening balance rule" label="View opening balance rule" align="start">
          Enter the financial position at the start of the selected fiscal year. Each Branch + Fiscal Year set must balance: total Debit equals total Credit. Only Posted opening balances affect Account Ledger.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Controlled starting Cash, Bank, Capital, Loan, payable and other account balances</p>
    </div>
    @perm('finance.opening-balances.create', 'add')
      <a href="{{ route('finance.opening-balances.create') }}" class="btn btn-primary app-primary-action d-inline-flex align-items-center gap-1 px-14 py-9 radius-8"><iconify-icon icon="mdi:plus"></iconify-icon><span>New Opening Balance</span></a>
    @endperm
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">Opening Balance Sets</h5>
      <div class="actions-bar d-flex align-items-center gap-2"><div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div><ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">@include('backend.include.buttons')</ul></div>
    </div>
    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="openingBalanceTable" style="width:100%">
        <thead><tr><th>S.L</th><th>Fiscal Year</th><th>Branch</th><th class="text-end">Lines</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th>Status</th><th>Action</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
  <script>var DATATABLE_URL = @json(route('finance.opening-balances.list.ajax'));</script>
@endsection
