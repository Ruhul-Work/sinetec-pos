@extends('backend.layouts.master')

@section('meta')
  <title>Wholesale Sales</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Wholesale Sales</h6>
        <x-backend.page-note title="Wholesale Sale workflow" label="View Wholesale Sale rules" align="start">
          A registered customer and due date are required. Draft and Ready to Post documents do not affect
          stock or Finance. Confirming a reviewed invoice posts stock, payments and Finance together.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Prepare customer invoices with negotiated product rates and payment terms</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
          Dashboard
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Wholesale Sales</li>
    </ul>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">Wholesale Sale Documents</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <select id="wholesaleStatusFilter" class="form-select form-select-sm w-auto">
          <option value="">All status</option>
          <option value="DRAFT">Draft</option>
          <option value="READY_TO_POST">Ready to Post</option>
          <option value="POSTED">Posted</option>
          <option value="VOID">Void</option>
        </select>
        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>
        @perm('sales.wholesale.store', 'add')
          <a href="{{ route('sales.wholesale.create') }}"
             class="btn btn-primary btn-sm px-12 py-8 radius-8 app-action-button">
            <iconify-icon icon="ic:baseline-plus" class="text-xl"></iconify-icon>
            <span>New Wholesale Sale</span>
          </a>
        @endperm
      </div>
    </div>

    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="wholesaleSaleTable" style="width: 100%">
        <thead>
          <tr>
            <th style="width: 60px">S.L</th>
            <th>Invoice No</th>
            <th>Date</th>
            <th>Customer</th>
            <th>Reference</th>
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
    var DATATABLE_URL = @json(route('sales.wholesale.list.ajax'));

    $(document).on('preXhr.dt', '#wholesaleSaleTable', function(event, settings, data) {
      data.status = $('#wholesaleStatusFilter').val();
    });

    $(document).on('change', '#wholesaleStatusFilter', function() {
      if ($.fn.DataTable.isDataTable('#wholesaleSaleTable')) {
        $('#wholesaleSaleTable').DataTable().ajax.reload();
      }
    });

    $(document).on('click', '.btn-wholesale-delete', function(event) {
      event.preventDefault();
      const url = $(this).data('url');
      const removeDraft = function() {
        $.ajax({
          url: url,
          type: 'POST',
          dataType: 'json',
          data: { _method: 'DELETE', _token: @json(csrf_token()) },
          success: function(response) {
            $('#wholesaleSaleTable').DataTable().ajax.reload(null, false);
            window.Swal && Swal.fire({
              icon: 'success',
              title: response?.msg || 'Draft deleted',
              timer: 1200,
              showConfirmButton: false,
            });
          },
          error: function(xhr) {
            const message = xhr.responseJSON?.msg || xhr.responseJSON?.message || 'Delete failed.';
            window.Swal ? Swal.fire({ icon: 'error', title: 'Failed', text: message }) : alert(message);
          },
        });
      };

      if (window.Swal) {
        Swal.fire({
          icon: 'warning',
          title: 'Delete this Wholesale Draft?',
          text: 'Only this unposted Draft will be removed.',
          showCancelButton: true,
          confirmButtonText: 'Yes, delete',
          confirmButtonColor: '#d33',
        }).then(function(result) {
          result.isConfirmed && removeDraft();
        });
      } else if (confirm('Delete this Wholesale Sale Draft?')) {
        removeDraft();
      }
    });
  </script>
@endsection
