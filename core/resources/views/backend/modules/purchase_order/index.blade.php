@extends('backend.layouts.master')

@section('meta') <title>Purchase Orders</title> @endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Purchase Orders</h6>
        <x-backend.page-note title="How purchase orders work" label="View purchase order rules" align="start">
          A purchase order records what we intend to buy from a supplier. It does not change warehouse stock or accounts.
          After approval, create a Purchase Receipt to record the actual quantity and supplier rate received.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Plan raw-material and purchasable-part orders before receipt</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium"><a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary"><iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard</a></li>
      <li>-</li><li class="fw-medium">Purchase Orders</li>
    </ul>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">Purchase Order Documents</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <select id="purchaseOrderStatusFilter" class="form-select form-select-sm w-auto">
          <option value="">All status</option>
          <option value="DRAFT">Draft</option><option value="APPROVED">Approved</option>
          <option value="PARTIAL">Partially Received</option><option value="RECEIVED">Received</option>
          <option value="CANCELLED">Cancelled</option>
        </select>
        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">@include('backend.include.buttons')</ul>
        @perm('purchase.orders.store', 'add')
          <a href="{{ route('purchase.orders.create') }}" class="btn btn-primary btn-sm px-12 py-8 radius-8 app-action-button"><iconify-icon icon="ic:baseline-plus" class="text-xl"></iconify-icon><span>Add Purchase Order</span></a>
        @endperm
      </div>
    </div>
    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="purchaseOrderTable" style="width:100%">
        <thead><tr><th style="width:60px">S.L</th><th>Order No</th><th>Order Date</th><th>Supplier</th><th>Warehouse</th><th>Lines</th><th>Total</th><th>Status</th><th style="width:130px">Action</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
<script>
  var DATATABLE_URL = @json(route('purchase.orders.list.ajax'));
  $(document).on('preXhr.dt', '#purchaseOrderTable', function(e, settings, data) {
    data.status = $('#purchaseOrderStatusFilter').val();
  });
  $(document).on('change', '#purchaseOrderStatusFilter', function() {
    if ($.fn.DataTable.isDataTable('#purchaseOrderTable')) $('#purchaseOrderTable').DataTable().ajax.reload();
  });
  $(document).on('click', '.btn-purchase-order-delete', function(e) {
    e.preventDefault();
    const url = $(this).data('url');
    const remove = () => $.ajax({
      url, type: 'POST', dataType: 'json', data: {_method: 'DELETE', _token: @json(csrf_token())},
      success: res => { $('#purchaseOrderTable').DataTable().ajax.reload(null, false); window.Swal && Swal.fire({icon: 'success', title: res?.msg || 'Deleted', timer: 1200, showConfirmButton: false}); },
      error: xhr => { const message = xhr.responseJSON?.msg || xhr.responseJSON?.message || 'Delete failed.'; window.Swal ? Swal.fire({icon: 'error', title: 'Failed', text: message}) : alert(message); }
    });
    if (window.Swal) {
      Swal.fire({icon: 'warning', title: 'Delete this draft?', text: 'This action removes the unapproved purchase order.', showCancelButton: true, confirmButtonText: 'Yes, delete', confirmButtonColor: '#d33'}).then(result => { if (result.isConfirmed) remove(); });
    } else if (confirm('Delete this purchase order draft?')) remove();
  });
</script>
@endsection
