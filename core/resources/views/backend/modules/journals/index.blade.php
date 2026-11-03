@extends('backend.layouts.master')

@section('meta')
  <title>Journal Entries</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Journal Entries</h6>
        <x-backend.page-note title="Journal workflow" label="View workflow rules" align="start">
          Business workflows create balanced Draft journals automatically. A balanced Draft must be approved before it can be posted. Only Posted journals affect account balances; a posted journal is corrected through a separate reversal draft, never by editing or voiding it.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Review finance drafts generated from operational documents</p>
    </div>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">Journal Documents</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <select id="journalSourceFilter" class="form-select form-select-sm w-auto">
          <option value="">All sources</option>
          <option value="purchase_receipt">Purchase Receipt</option>
          <option value="purchase_return">Purchase Return</option>
          <option value="journal_reversal">Journal Reversal</option>
        </select>
        <select id="journalStatusFilter" class="form-select form-select-sm w-auto">
          <option value="">All status</option>
          <option value="DRAFT">Draft</option>
          <option value="POSTED">Posted</option>
          <option value="VOID">Void</option>
        </select>
        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>
      </div>
    </div>

    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="journalTable" style="width:100%">
        <thead>
          <tr>
            <th>S.L</th>
            <th>Voucher</th>
            <th>Date</th>
            <th>Branch</th>
            <th>Voucher Type</th>
            <th>Source</th>
            <th>Lines</th>
            <th class="text-end">Debit</th>
            <th class="text-end">Credit</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
  <script>
    var DATATABLE_URL = @json(route('finance.journals.list.ajax'));

    $(document).on('preXhr.dt', '#journalTable', function (event, settings, data) {
      data.status = $('#journalStatusFilter').val();
      data.source_type = $('#journalSourceFilter').val();
    });

    $('#journalStatusFilter, #journalSourceFilter').on('change', function () {
      if ($.fn.DataTable.isDataTable('#journalTable')) {
        $('#journalTable').DataTable().ajax.reload();
      }
    });
  </script>
@endsection
