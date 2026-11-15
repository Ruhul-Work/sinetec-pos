@extends('backend.layouts.master')

@section('meta')
  <title>Finished Product BOM / Recipe</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <h6 class="fw-semibold mb-0">Finished Product BOM / Recipe</h6>
      <p class="text-muted m-0">
        Define the Parts and Raw Materials required to assemble a Finished Product.
      </p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a
          href="{{ route('backend.dashboard') }}"
          class="d-flex align-items-center gap-1 hover-text-primary"
        >
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
          Dashboard
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Finished Product BOM</li>
    </ul>
  </div>

  @if (session('success'))
    <div class="alert alert-success d-flex align-items-center gap-2">
      <iconify-icon icon="mdi:check-circle-outline" class="text-xl"></iconify-icon>
      {{ session('success') }}
    </div>
  @endif

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <div class="d-flex align-items-center gap-2">
        <h5 class="card-title mb-0">Finished Product BOM List</h5>
        <x-backend.field-note label="Finished Product BOM rule">
          A Finished Product can have multiple recipe versions, but only one version can be Active at a time.
          Raw Material and Part components may be mixed in the same recipe.
        </x-backend.field-note>
      </div>

      <div class="d-flex align-items-center gap-2 flex-wrap part-bom-index-actions">
        <select
          id="productBomStatusFilter"
          class="form-select form-select-sm part-bom-status-filter"
        >
          <option value="">All status</option>
          <option value="DRAFT">Draft</option>
          <option value="ACTIVE">Active</option>
          <option value="INACTIVE">Inactive</option>
        </select>
        <div class="search-set">
          <div id="tableSearch" class="search-input"></div>
        </div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>
        @perm('production.product-boms.create', 'add')
          <a
            href="{{ route('production.product-boms.create') }}"
            class="btn btn-primary btn-sm px-16 py-8 radius-8 app-action-button app-primary-action"
          >
            <iconify-icon icon="mdi:playlist-plus"></iconify-icon>
            <span>Create Product Recipe</span>
          </a>
        @endperm
      </div>
    </div>

    <div class="card-body">
      <div class="table-responsive">
        <table
          class="table bordered-table table-scroll mb-0 AjaxDataTable"
          id="productBomsTable"
          style="width: 100%"
        >
          <thead>
            <tr>
              <th style="width: 60px">S.L</th>
              <th>Finished Product</th>
              <th>Version</th>
              <th class="text-end">Output Qty</th>
              <th class="text-center">Components</th>
              <th>Effective Dates</th>
              <th>Status</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
@endsection

@section('script')
<script>
  var DATATABLE_URL = @json(route('production.product-boms.list.ajax'));

  window.ProductBomIndex = {
    onValiditySaved: function() {
      if ($.fn.DataTable.isDataTable('#productBomsTable')) {
        $('#productBomsTable').DataTable().ajax.reload(null, false);
      }
    },
  };

  $(document).on('preXhr.dt', '#productBomsTable', function(event, settings, data) {
    data.status = $('#productBomStatusFilter').val();
  });

  $(document).on('change', '#productBomStatusFilter', function() {
    if ($.fn.DataTable.isDataTable('#productBomsTable')) {
      $('#productBomsTable').DataTable().ajax.reload();
    }
  });

  $(document).on('click', '.btn-product-bom-delete', function() {
    const url = $(this).data('url');

    const submitDelete = function() {
      $.ajax({
        url: url,
        type: 'POST',
        data: {
          _method: 'DELETE',
          _token: @json(csrf_token()),
        },
      }).done(function(response) {
        $('#productBomsTable').DataTable().ajax.reload(null, false);
        Swal.fire({
          icon: 'success',
          title: response?.msg || 'Draft deleted.',
          timer: 1100,
          showConfirmButton: false,
        });
      }).fail(function(xhr) {
        Swal.fire({
          icon: 'error',
          title: 'Delete failed',
          text: xhr.responseJSON?.message || 'The Draft BOM could not be deleted.',
        });
      });
    };

    Swal.fire({
      icon: 'warning',
      title: 'Delete this Draft BOM?',
      text: 'Only unused Draft BOMs can be deleted.',
      showCancelButton: true,
      confirmButtonText: 'Delete',
      confirmButtonColor: '#dc3545',
    }).then(function(result) {
      if (result.isConfirmed) {
        submitDelete();
      }
    });
  });
</script>
@endsection
