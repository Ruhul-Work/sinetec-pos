@extends('backend.layouts.master')

@section('meta') <title>Stock Ledger</title> @endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Stock Ledger</h6>
        <x-backend.page-note title="How to read the ledger" label="View stock ledger note" align="start">
          Every posted Opening Stock, Stock Transfer and Stock Adjustment creates an immutable movement here.
          IN adds stock, OUT removes stock, and Running Balance is calculated chronologically in the item's base unit.
          Date and movement filters hide rows only; they do not reset the true running balance.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Complete warehouse-wise movement history for raw materials, parts and finished products</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium"><a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary"><iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard</a></li>
      <li>-</li><li class="fw-medium">Stock Ledger</li>
    </ul>
  </div>

  <div class="card mb-24">
    <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <h5 class="card-title mb-0">Ledger Filters</h5>
      <button type="button" id="resetLedgerFilters" class="btn btn-outline-secondary btn-sm px-12 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:filter-remove-outline"></iconify-icon><span>Reset Filters</span></button>
    </div>
    <div class="card-body">
      <div class="row gy-3 align-items-end" id="stockLedgerFilters">
        <div class="col-xl-2 col-md-4">
          <label class="form-label text-sm">Inventory Type</label>
          <select id="ledgerDomain" class="form-control js-s2" placeholder="All inventory types">
            <option value="">All inventory types</option>
            @foreach ($domains as $domain => $config)<option value="{{ $domain }}">{{ $config['label'] }}</option>@endforeach
          </select>
        </div>
        <div class="col-xl-3 col-md-4">
          <label class="form-label text-sm">Item</label>
          <select id="ledgerItem" class="form-control" placeholder="Select inventory type first" disabled></select>
        </div>
        <div class="col-xl-2 col-md-4">
          <label class="form-label text-sm">Branch</label>
          <select id="ledgerBranch" class="form-control js-s2" placeholder="All branches">
            <option value="">All branches</option>
            @foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }} ({{ $branch->code }})</option>@endforeach
          </select>
        </div>
        <div class="col-xl-3 col-md-6">
          <label class="form-label text-sm">Warehouse</label>
          <select id="ledgerWarehouse" class="form-control js-s2" placeholder="All warehouses">
            <option value="">All warehouses</option>
            @foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}" data-branch="{{ $warehouse->branch_id }}">{{ $warehouse->name }} ({{ $warehouse->code }}) — {{ $warehouse->branch_name }}</option>@endforeach
          </select>
        </div>
        <div class="col-xl-2 col-md-3">
          <label class="form-label text-sm">Direction</label>
          <select id="ledgerDirection" class="form-control js-s2"><option value="">IN & OUT</option><option value="IN">IN</option><option value="OUT">OUT</option></select>
        </div>
        <div class="col-xl-3 col-md-5">
          <label class="form-label text-sm">Movement Type</label>
          <select id="ledgerMovementType" class="form-control js-s2" placeholder="All movement types">
            <option value="">All movement types</option>
            @foreach ($movementTypes as $movementType)<option value="{{ $movementType->value }}">{{ str($movementType->value)->replace('_', ' ')->title() }}</option>@endforeach
          </select>
        </div>
        <div class="col-xl-2 col-md-3">
          <label class="form-label text-sm">From Date</label>
          <input type="date" id="ledgerDateFrom" class="form-control" value="{{ now()->startOfMonth()->format('Y-m-d') }}">
        </div>
        <div class="col-xl-2 col-md-3">
          <label class="form-label text-sm">To Date</label>
          <input type="date" id="ledgerDateTo" class="form-control" value="{{ now()->format('Y-m-d') }}">
        </div>
        <div class="col-xl-2 col-md-3">
          <button type="button" id="applyLedgerFilters" class="btn btn-primary w-100 px-14 py-10 radius-8 app-action-button app-primary-action"><iconify-icon icon="mdi:magnify"></iconify-icon><span>Apply Filters</span></button>
        </div>
      </div>
    </div>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">Movement Ledger</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">@include('backend.include.buttons')</ul>
      </div>
    </div>
    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="stockLedgerTable" style="width:100%">
        <thead><tr>
          <th style="width:60px">S.L</th><th>Date</th><th>Inventory Type</th><th>Item</th><th>Location</th><th>Movement</th><th>Reference</th><th class="text-end">IN</th><th class="text-end">OUT</th><th class="text-end">Unit Cost</th><th class="text-end">Running Balance</th><th>Posted By</th><th style="width:80px">Document</th>
        </tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
<script>
  var DATATABLE_URL = @json(route('inventory.stock-ledger.list.ajax'));

  (function($) {
    const itemUrl = @json(route('inventory.stock-ledger.items.select2'));
    const defaultDateFrom = @json(now()->startOfMonth()->format('Y-m-d'));
    const defaultDateTo = @json(now()->format('Y-m-d'));

    S2.auto($('#stockLedgerFilters'));
    S2.init($('#ledgerItem'), {
      ajax: {
        url: itemUrl,
        dataType: 'json',
        delay: 250,
        data: params => ({q: params.term || '', domain: $('#ledgerDomain').val()}),
        processResults: data => ({results: data.results || []})
      }
    });

    function reloadLedger() {
      if ($.fn.DataTable.isDataTable('#stockLedgerTable')) $('#stockLedgerTable').DataTable().ajax.reload();
    }

    $(document).on('preXhr.dt', '#stockLedgerTable', function(e, settings, data) {
      data.domain = $('#ledgerDomain').val();
      data.item_id = $('#ledgerItem').val();
      data.branch_id = $('#ledgerBranch').val();
      data.warehouse_id = $('#ledgerWarehouse').val();
      data.movement_type = $('#ledgerMovementType').val();
      data.direction = $('#ledgerDirection').val();
      data.date_from = $('#ledgerDateFrom').val();
      data.date_to = $('#ledgerDateTo').val();
    });

    $('#ledgerDomain').on('change', function() {
      $('#ledgerItem').empty().prop('disabled', !this.value).trigger('change');
    });
    $('#ledgerBranch').on('change', function() {
      const branchId = this.value;
      const selectedWarehouse = $('#ledgerWarehouse option:selected').data('branch');
      if (branchId && String(selectedWarehouse || '') !== String(branchId)) $('#ledgerWarehouse').val('').trigger('change');
    });
    $('#applyLedgerFilters').on('click', reloadLedger);
    $('#stockLedgerFilters select:not(#ledgerItem), #stockLedgerFilters input').on('change', function() {
      if (this.id !== 'ledgerDomain') reloadLedger();
    });
    $('#resetLedgerFilters').on('click', function() {
      $('#ledgerDomain, #ledgerBranch, #ledgerWarehouse, #ledgerMovementType, #ledgerDirection').val('').trigger('change');
      $('#ledgerItem').empty().prop('disabled', true).trigger('change');
      $('#ledgerDateFrom').val(defaultDateFrom);
      $('#ledgerDateTo').val(defaultDateTo);
      reloadLedger();
    });
  })(jQuery);
</script>
@endsection
