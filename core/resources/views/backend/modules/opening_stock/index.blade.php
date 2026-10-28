@extends('backend.layouts.master')

@section('meta')
  <title>Opening Stock</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Opening Stock</h6>
        <x-backend.page-note title="How opening stock works" label="View opening stock note" align="start">
          Opening stock records the stock already available when this system starts. Save the document as Draft, verify it,
          Approve it, then Post it. Posting creates permanent stock ledger entries and warehouse balances. It is allowed only
          before an item has any other movement in that warehouse.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Controlled starting quantities for raw materials, parts and finished products</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium"><a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary"><iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard</a></li>
      <li>-</li>
      <li class="fw-medium">Opening Stock</li>
    </ul>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">Opening Stock Documents</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <select id="openingStatusFilter" class="form-select form-select-sm w-auto">
          <option value="">All status</option>
          <option value="DRAFT">Draft</option>
          <option value="APPROVED">Approved</option>
          <option value="POSTED">Posted</option>
        </select>
        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>
        @perm('inventory.opening-stocks.store', 'add')
          <a href="{{ route('inventory.opening-stocks.create') }}" class="btn btn-primary btn-sm px-12 py-8 radius-8 app-action-button">
            <iconify-icon icon="ic:baseline-plus" class="text-xl"></iconify-icon><span>Add Opening Stock</span>
          </a>
        @endperm
      </div>
    </div>
    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="openingStockTable" style="width:100%">
        <thead>
          <tr>
            <th style="width:60px">S.L</th>
            <th>Document No</th>
            <th>Date</th>
            <th>Branch</th>
            <th>Warehouse</th>
            <th>Lines</th>
            <th>Status</th>
            <th style="width:130px">Action</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
<script>
  var DATATABLE_URL = @json(route('inventory.opening-stocks.list.ajax'));

  $(document).on('preXhr.dt', '#openingStockTable', function(e, settings, data) {
    data.status = $('#openingStatusFilter').val();
  });

  $(document).on('change', '#openingStatusFilter', function() {
    if ($.fn.DataTable.isDataTable('#openingStockTable')) {
      $('#openingStockTable').DataTable().ajax.reload();
    }
  });

  $(document).on('click', '.btn-opening-delete', function(e) {
    e.preventDefault();
    const url = $(this).data('url');
    const remove = () => $.ajax({
      url: url,
      type: 'POST',
      dataType: 'json',
      data: {_method: 'DELETE', _token: @json(csrf_token())},
      success: function(res) {
        $('#openingStockTable').DataTable().ajax.reload(null, false);
        window.Swal && Swal.fire({icon: 'success', title: res?.msg || 'Deleted', timer: 1200, showConfirmButton: false});
      },
      error: function(xhr) {
        const message = xhr.responseJSON?.msg || xhr.responseJSON?.message || 'Delete failed.';
        window.Swal ? Swal.fire({icon: 'error', title: 'Failed', text: message}) : alert(message);
      }
    });

    if (window.Swal) {
      Swal.fire({icon: 'warning', title: 'Delete this draft?', text: 'This action removes the unposted document.', showCancelButton: true, confirmButtonText: 'Yes, delete', confirmButtonColor: '#d33'})
        .then(result => { if (result.isConfirmed) remove(); });
    } else if (confirm('Delete this opening stock draft?')) {
      remove();
    }
  });
</script>
@endsection
