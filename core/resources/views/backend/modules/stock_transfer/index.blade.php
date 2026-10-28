@extends('backend.layouts.master')

@section('meta') <title>Stock Transfer</title> @endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Stock Transfer</h6>
        <x-backend.page-note title="How stock transfer works" label="View stock transfer note" align="start">
          A Draft has no stock effect. Posting creates one permanent TRANSFER_OUT movement in the source warehouse and one
          TRANSFER_IN movement in the destination warehouse within the same database transaction. Insufficient source stock
          cancels the complete posting.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Move raw materials, parts or finished products between stock locations</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium"><a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary"><iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard</a></li>
      <li>-</li><li class="fw-medium">Stock Transfer</li>
    </ul>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">Stock Transfer Documents</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <select id="stockTransferStatusFilter" class="form-select form-select-sm w-auto">
          <option value="">All status</option><option value="DRAFT">Draft</option><option value="POSTED">Posted</option>
        </select>
        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">@include('backend.include.buttons')</ul>
        @perm('inventory.stock-transfers.store', 'add')
          <a href="{{ route('inventory.stock-transfers.create') }}" class="btn btn-primary btn-sm px-12 py-8 radius-8 app-action-button"><iconify-icon icon="ic:baseline-plus" class="text-xl"></iconify-icon><span>Add Stock Transfer</span></a>
        @endperm
      </div>
    </div>
    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="stockTransferTable" style="width:100%">
        <thead><tr><th style="width:60px">S.L</th><th>Transfer No</th><th>Date</th><th>Source</th><th>Destination</th><th>Lines</th><th>Status</th><th style="width:130px">Action</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
<script>
  var DATATABLE_URL = @json(route('inventory.stock-transfers.list.ajax'));
  $(document).on('preXhr.dt', '#stockTransferTable', function(e, settings, data) { data.status = $('#stockTransferStatusFilter').val(); });
  $(document).on('change', '#stockTransferStatusFilter', function() { if ($.fn.DataTable.isDataTable('#stockTransferTable')) $('#stockTransferTable').DataTable().ajax.reload(); });
  $(document).on('click', '.btn-stock-transfer-delete', function(e) {
    e.preventDefault();
    const url = $(this).data('url');
    const remove = () => $.ajax({
      url: url, type: 'POST', dataType: 'json', data: {_method: 'DELETE', _token: @json(csrf_token())},
      success: function(res) {
        $('#stockTransferTable').DataTable().ajax.reload(null, false);
        window.Swal && Swal.fire({icon: 'success', title: res?.msg || 'Deleted', timer: 1200, showConfirmButton: false});
      },
      error: function(xhr) {
        const message = xhr.responseJSON?.msg || xhr.responseJSON?.message || 'Delete failed.';
        window.Swal ? Swal.fire({icon: 'error', title: 'Failed', text: message}) : alert(message);
      }
    });
    if (window.Swal) {
      Swal.fire({icon: 'warning', title: 'Delete this draft?', text: 'This removes the unposted transfer document.', showCancelButton: true, confirmButtonText: 'Yes, delete', confirmButtonColor: '#d33'}).then(result => { if (result.isConfirmed) remove(); });
    } else if (confirm('Delete this stock transfer draft?')) remove();
  });
</script>
@endsection
