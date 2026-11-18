@extends('backend.layouts.master')

@section('meta')
  <title>POS Sales</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">POS Sales</h6>
        <x-backend.page-note title="POS Sale workflow" label="View POS Sale rules" align="start">
          Draft and Ready to Post documents have no stock or Finance effect. Confirming a reviewed Sale
          posts stock, payments and its balanced journal together, then opens the thermal receipt.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Prepare fast Finished Product counter sales</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
          Dashboard
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">POS Sales</li>
    </ul>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">POS Sale Documents</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <select id="posSaleStatusFilter" class="form-select form-select-sm w-auto">
          <option value="" @selected(request('status') === null || request('status') === '')>All status</option>
          <option value="DRAFT" @selected(request('status') === 'DRAFT')>Draft</option>
          <option value="READY_TO_POST" @selected(request('status') === 'READY_TO_POST')>Ready to Post</option>
          <option value="POSTED" @selected(request('status') === 'POSTED')>Posted</option>
          <option value="VOID" @selected(request('status') === 'VOID')>Void</option>
        </select>
        <div class="search-set me-2">
          <div id="tableSearch" class="search-input"></div>
        </div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>
        @perm('sales.pos.store', 'add')
          <a href="{{ route('sales.pos.create') }}" class="btn btn-primary btn-sm px-12 py-8 radius-8 app-action-button">
            <iconify-icon icon="ic:baseline-plus" class="text-xl"></iconify-icon>
            <span>New POS Sale</span>
          </a>
        @endperm
      </div>
    </div>

    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="posSaleTable" style="width: 100%">
        <thead>
          <tr>
            <th style="width: 60px">S.L</th>
            <th>Sale No</th>
            <th>Date & Time</th>
            <th>Customer</th>
            <th>Warehouse</th>
            <th>Items</th>
            <th>Total</th>
            <th>Status</th>
            <th>Payment</th>
            <th style="width: 150px">Action</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
  <script>
    var DATATABLE_URL = @json(route('sales.pos.list.ajax'));

    $(document).on('preXhr.dt', '#posSaleTable', function(event, settings, data) {
      data.status = $('#posSaleStatusFilter').val();
    });

    $(document).on('change', '#posSaleStatusFilter', function() {
      if ($.fn.DataTable.isDataTable('#posSaleTable')) {
        $('#posSaleTable').DataTable().ajax.reload();
      }
    });

    $(document).on('click', '.btn-pos-sale-delete', function(event) {
      event.preventDefault();
      const url = $(this).data('url');

      const removeDraft = function() {
        $.ajax({
          url: url,
          type: 'POST',
          dataType: 'json',
          data: {
            _method: 'DELETE',
            _token: @json(csrf_token()),
          },
          success: function(response) {
            $('#posSaleTable').DataTable().ajax.reload(null, false);
            if (window.Swal) {
              Swal.fire({
                icon: 'success',
                title: response?.msg || 'Draft deleted',
                timer: 1200,
                showConfirmButton: false,
              });
            }
          },
          error: function(xhr) {
            const message = xhr.responseJSON?.msg || xhr.responseJSON?.message || 'Delete failed.';
            window.Swal
              ? Swal.fire({ icon: 'error', title: 'Failed', text: message })
              : alert(message);
          },
        });
      };

      if (window.Swal) {
        Swal.fire({
          icon: 'warning',
          title: 'Delete this POS Draft?',
          text: 'Only this unposted Draft will be removed.',
          showCancelButton: true,
          confirmButtonText: 'Yes, delete',
          confirmButtonColor: '#d33',
        }).then(function(result) {
          if (result.isConfirmed) {
            removeDraft();
          }
        });
      } else if (confirm('Delete this POS Sale Draft?')) {
        removeDraft();
      }
    });
  </script>
@endsection
