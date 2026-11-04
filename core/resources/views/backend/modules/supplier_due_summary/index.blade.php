@extends('backend.layouts.master')

@section('meta')
  <title>Supplier Due Summary</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Supplier Due Summary</h6>
        <x-backend.page-note title="Supplier due calculation" label="View due rule" align="start">
          Due is calculated from Posted purchase receipts minus Posted returns and Posted supplier payments for the current branch. Draft, Void and Reversed payments never reduce due.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Supplier-wise payable position from approved purchase documents</p>
    </div>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">Current Supplier Balances</h5>
      <div class="actions-bar d-flex align-items-center gap-2"><div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div><ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">@include('backend.include.buttons')</ul></div>
    </div>
    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="supplierDueTable" style="width:100%">
        <thead><tr><th>S.L</th><th>Supplier</th><th class="text-end">Receipts</th><th class="text-end">Purchase</th><th class="text-end">Return</th><th class="text-end">Paid</th><th class="text-end">Due</th><th class="text-end">Supplier Credit</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
  <script>var DATATABLE_URL = @json(route('purchase.supplier-dues.list.ajax'));</script>
@endsection
