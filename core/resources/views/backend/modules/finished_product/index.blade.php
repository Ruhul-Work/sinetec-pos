@extends('backend.layouts.master')

@section('meta')
  <title>Finished Products</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <h6 class="fw-semibold mb-0">Finished Products</h6>
      <p class="text-muted m-0">Sellable products completed through assembly</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Finished Products</li>
    </ul>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">Finished Product List</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <select id="finishedProductStatusFilter" class="form-select form-select-sm w-auto">
          <option value="">All status</option>
          <option value="1">Active</option>
          <option value="0">Inactive</option>
        </select>
        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>

        @perm('inventory.finished-products.store', 'add')
          <button class="d-flex btn btn-primary btn-sm px-12 py-8 radius-8 AjaxModal"
                  data-ajax-modal="{{ route('inventory.finished-products.createModal') }}"
                  data-size="lg" data-onload="FinishedProductsIndex.onLoad" data-onsuccess="FinishedProductsIndex.onSaved">
            <iconify-icon icon="ic:baseline-plus" class="text-xl"></iconify-icon> Add Finished Product
          </button>
        @endperm
      </div>
    </div>

    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="finishedProductsTable" style="width:100%">
        <thead>
          <tr>
            <th style="width:60px">S.L</th>
            <th>Image</th>
            <th>Code</th>
            <th>Name / Barcode</th>
            <th>Category</th>
            <th>Base Unit</th>
            <th>Brand</th>
            <th>Retail</th>
            <th>Wholesale</th>
            <th>Status</th>
            <th style="width:120px">Action</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
<script>
  var DATATABLE_URL = @json(route('inventory.finished-products.list.ajax'));

  window.FinishedProductsIndex = {
    onLoad: function($modal) {
      S2.auto($modal);
    },
    onSaved: function() {
      $('#finishedProductsTable').DataTable().ajax.reload(null, false);
    }
  };

  $(document).on('preXhr.dt', '#finishedProductsTable', function(e, settings, data) {
    data.status = $('#finishedProductStatusFilter').val();
  });

  $(document).on('change', '#finishedProductStatusFilter', function() {
    if ($.fn.DataTable.isDataTable('#finishedProductsTable')) {
      $('#finishedProductsTable').DataTable().ajax.reload();
    }
  });

  $(document).on('click', '.btn-finished-product-delete', function(e) {
    e.preventDefault();
    const url = $(this).data('url');

    const doDelete = () => $.ajax({
      url: url,
      type: 'POST',
      dataType: 'json',
      data: {_method: 'DELETE', _token: @json(csrf_token())},
      success: function(res) {
        $('#finishedProductsTable').DataTable().ajax.reload(null, false);
        window.Swal && Swal.fire({icon: 'success', title: res?.msg || 'Deleted', timer: 1000, showConfirmButton: false});
      },
      error: function(xhr) {
        const message = xhr.responseJSON?.msg || xhr.responseJSON?.message || 'Delete failed.';
        window.Swal
          ? Swal.fire({icon: xhr.status === 422 ? 'warning' : 'error', title: xhr.status === 422 ? 'Blocked' : 'Failed', text: message})
          : alert(message);
      }
    });

    if (window.Swal) {
      Swal.fire({
        icon: 'warning',
        title: 'Delete finished product?',
        text: 'Referenced products cannot be deleted. Use inactive status when history exists.',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete',
        confirmButtonColor: '#d33'
      }).then(result => { if (result.isConfirmed) doDelete(); });
    } else if (confirm('Delete this finished product?')) {
      doDelete();
    }
  });
</script>
@endsection
