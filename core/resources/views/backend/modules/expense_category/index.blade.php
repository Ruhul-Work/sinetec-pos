@extends('backend.layouts.master')

@section('meta')
  <title>General Expense Categories</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">General Expense Categories</h6>
        <x-backend.page-note title="How general expense categories work" label="View category rules" align="start">
          Use these categories for office, administration and other period expenses paid from Cash or Bank.
          Direct production costs use the separate Production Cost Categories screen.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Map each general expense category to its own leaf Expense account.</p>
    </div>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">General Category & Account Mapping</h5>

      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <div class="search-set me-2">
          <div id="tableSearch" class="search-input"></div>
        </div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>

        @perm('finance.expense-categories.store', 'add')
          <button
            type="button"
            class="btn btn-primary btn-sm px-12 py-8 radius-8 app-action-button AjaxModal"
            data-ajax-modal="{{ route('finance.expense-categories.createModal') }}"
            data-size="md"
            data-onload="GeneralExpenseCategoriesIndex.onLoad"
            data-onsuccess="GeneralExpenseCategoriesIndex.onSaved"
          >
            <iconify-icon icon="ic:baseline-plus" class="text-xl"></iconify-icon>
            <span>Add Category</span>
          </button>
        @endperm
      </div>
    </div>

    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" style="width: 100%">
        <thead>
          <tr>
            <th style="width: 60px">S.L</th>
            <th>Code</th>
            <th>Category</th>
            <th>Expense Account</th>
            <th>Status</th>
            <th style="width: 110px" class="text-end">Action</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
<script>
  var DATATABLE_URL = @json(route('finance.expense-categories.list.ajax'));

  window.GeneralExpenseCategoriesIndex = {
    onLoad: function($modal) {
      S2.auto($modal);
    },
    onSaved: function() {
      $('.AjaxDataTable').DataTable().ajax.reload(null, false);
    }
  };

  $(document).on('click', '.btn-general-expense-category-delete', function(event) {
    event.preventDefault();
    const url = $(this).data('url');

    const deleteCategory = function() {
      $.ajax({
        url: url,
        type: 'POST',
        dataType: 'json',
        data: {
          _method: 'DELETE',
          _token: @json(csrf_token())
        },
        success: function(response) {
          $('.AjaxDataTable').DataTable().ajax.reload(null, false);
          Swal.fire({
            icon: 'success',
            title: response.msg || 'Category deleted',
            timer: 1200,
            showConfirmButton: false
          });
        },
        error: function(xhr) {
          Swal.fire({
            icon: xhr.status === 422 ? 'warning' : 'error',
            title: xhr.status === 422 ? 'Delete blocked' : 'Delete failed',
            text: xhr.responseJSON?.msg || xhr.responseJSON?.message || 'Unable to delete the category.'
          });
        }
      });
    };

    Swal.fire({
      icon: 'warning',
      title: 'Delete this category?',
      text: 'A used category cannot be deleted and should be made inactive instead.',
      showCancelButton: true,
      confirmButtonText: 'Yes, delete',
      confirmButtonColor: '#dc3545'
    }).then(function(result) {
      if (result.isConfirmed) {
        deleteCategory();
      }
    });
  });
</script>
@endsection
