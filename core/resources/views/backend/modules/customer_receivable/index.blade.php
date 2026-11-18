@extends('backend.layouts.master')

@section('meta')
  <title>Customer Receivables</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Customer Receivables</h6>
        <x-backend.page-note title="Customer due collection" label="View collection rules" align="start">
          This list shows Posted Sales with a remaining Customer due. Collection posts Cash, Bank or MFS
          and reduces Customer Receivable without changing the original Sale or stock.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Review outstanding Sales and collect full or partial payments</p>
    </div>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="{{ route('sales.customer-receivables.index') }}"
           class="btn btn-sm px-12 py-7 radius-8 {{ $activeTab === 'outstanding' ? 'btn-primary' : 'btn-outline-primary' }}">
          Outstanding Dues
        </a>
        <a href="{{ route('sales.customer-receivables.index', ['tab' => 'history']) }}"
           class="btn btn-sm px-12 py-7 radius-8 {{ $activeTab === 'history' ? 'btn-primary' : 'btn-outline-primary' }}">
          Collection History
        </a>
      </div>
      <div class="actions-bar d-flex align-items-center gap-2">
        <div class="search-set me-2">
          <div id="tableSearch" class="search-input"></div>
        </div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>
      </div>
    </div>
    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="customerReceivableTable" style="width: 100%">
        <thead>
          <tr>
            <th style="width: 60px">S.L</th>
            @if ($activeTab === 'history')
              <th>Receipt No</th>
              <th>Collection Date</th>
              <th>Customer / Branch</th>
              <th>Sale No</th>
              <th>Methods</th>
              <th class="text-end">Collected</th>
              <th class="text-end">Due Before</th>
              <th class="text-end">Due After</th>
            @else
              <th>Sale No</th>
              <th>Sale Date</th>
              <th>Customer</th>
              <th>Branch</th>
              <th class="text-end">Sale Total</th>
              <th class="text-end">Paid</th>
              <th class="text-end">Due</th>
            @endif
            <th style="width: 100px">Action</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
  <script>
    var DATATABLE_URL = @json(route('sales.customer-receivables.list.ajax'));

    $(document).on('preXhr.dt', '#customerReceivableTable', function(event, settings, data) {
      data.tab = @json($activeTab);
    });

    window.CustomerReceivables = {
      onCollected: function() {
        if ($.fn.DataTable.isDataTable('#customerReceivableTable')) {
          $('#customerReceivableTable').DataTable().ajax.reload(null, false);
        }
      }
    };
  </script>
@endsection
