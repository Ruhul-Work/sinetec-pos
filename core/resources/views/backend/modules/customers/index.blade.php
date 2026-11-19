@extends('backend.layouts.master')

@section('meta')
  <title>Customers</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Customers</h6>
        <x-backend.page-note title="Customer master" label="View customer rules" align="start">
          Customers are company-wide master records shared by POS, Wholesale Sales, Due Collection and Sales Return.
          Walk-in POS sales do not require a saved customer. Used customers should be made inactive instead of deleted.
        </x-backend.page-note>
      </div>
      <p class="text-muted mb-0">Manage registered customers used by sales and receivables.</p>
    </div>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">Customer List</h5>

      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <select id="customerStatusFilter" class="form-select form-select-sm w-auto">
          <option value="">All status</option>
          <option value="ACTIVE">Active</option>
          <option value="INACTIVE">Inactive</option>
        </select>
        <div class="search-set me-2">
          <div id="tableSearch" class="search-input"></div>
        </div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>

        @perm('customer.store', 'add')
          <button
            type="button"
            class="btn btn-primary btn-sm px-12 py-8 radius-8 app-action-button AjaxModal"
            data-ajax-modal="{{ route('customer.createModal') }}"
            data-size="lg"
            data-onsuccess="CustomerIndex.onSaved"
          >
            <iconify-icon icon="mdi:account-plus-outline" class="text-xl"></iconify-icon>
            <span>Add Customer</span>
          </button>
        @endperm
      </div>
    </div>

    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="customersTable" style="width: 100%">
        <thead>
          <tr>
            <th style="width: 60px">S.L</th>
            <th>Customer</th>
            <th>Contact</th>
            <th>Address</th>
            <th>Status</th>
            <th class="text-end" style="width: 110px">Action</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
<script>
  var DATATABLE_URL = @json(route('customer.list.ajax'));

  window.CustomerIndex = {
    onSaved: function() {
      if ($.fn.DataTable.isDataTable('#customersTable')) {
        $('#customersTable').DataTable().ajax.reload(null, false);
      }
    }
  };

  $(document).on('preXhr.dt', '#customersTable', function(event, settings, data) {
    data.status = $('#customerStatusFilter').val();
  });

  $(document).on('change', '#customerStatusFilter', function() {
    if ($.fn.DataTable.isDataTable('#customersTable')) {
      $('#customersTable').DataTable().ajax.reload();
    }
  });

  $(document).on('click', '.btn-customer-delete', function(event) {
    event.preventDefault();
    const url = $(this).data('url');

    const deleteCustomer = function() {
      $.ajax({
        url: url,
        type: 'POST',
        dataType: 'json',
        data: {
          _method: 'DELETE',
          _token: @json(csrf_token())
        }
      }).done(function(response) {
        $('#customersTable').DataTable().ajax.reload(null, false);
        Swal.fire({
          icon: 'success',
          title: response.msg || 'Customer deleted',
          timer: 1200,
          showConfirmButton: false
        });
      }).fail(function(xhr) {
        Swal.fire({
          icon: xhr.status === 422 ? 'warning' : 'error',
          title: xhr.status === 422 ? 'Delete blocked' : 'Delete failed',
          text: xhr.responseJSON?.msg || xhr.responseJSON?.message || 'Unable to delete the customer.'
        });
      });
    };

    Swal.fire({
      icon: 'warning',
      title: 'Delete this customer?',
      text: 'A used customer cannot be deleted and should be made inactive instead.',
      showCancelButton: true,
      confirmButtonText: 'Yes, delete',
      confirmButtonColor: '#dc3545'
    }).then(function(result) {
      if (result.isConfirmed) {
        deleteCustomer();
      }
    });
  });
</script>
@endsection
