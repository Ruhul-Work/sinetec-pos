@extends('backend.layouts.master')

@section('meta')
    <title>Account Types</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <h6 class="fw-semibold mb-0">Account Types</h6>
            <p class="text-muted m-0">Normal-balance rules for the Chart of Accounts</p>
        </div>
    </div>

    <div class="card basic-data-table">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <h5 class="card-title mb-0">Account Types</h5>
            <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
                <div class="search-set me-2">
                    <div id="tableSearch" class="search-input"></div>
                </div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
                    @include('backend.include.buttons')
                </ul>
                @perm('account-types.store')
                    <button
                        type="button"
                        class="btn btn-primary btn-sm px-12 py-8 radius-8 app-action-button AjaxModal"
                        data-ajax-modal="{{ route('account-types.createModal') }}"
                        data-size="sm"
                        data-onsuccess="AccountTypesIndex.onSaved"
                    >
                        <iconify-icon icon="ic:baseline-plus"></iconify-icon>
                        <span>Add Type</span>
                    </button>
                @endperm
            </div>
        </div>

        <div class="card-body">
            <table
                class="table bordered-table table-scroll mb-0 AjaxDataTable"
                id="accountTypesTable"
                style="width: 100%"
            >
                <thead>
                    <tr>
                        <th>S.L</th>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Normal Balance</th>
                        <th>Source</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
@endsection

@section('script')
    <script>
        var DATATABLE_URL = @json(route('account-types.list.ajax'));

        window.AccountTypesIndex = {
            onSaved: function () {
                $('#accountTypesTable').DataTable().ajax.reload(null, false);
            },
        };

        $(document).on('click', '.btn-account-type-del', function (event) {
            event.preventDefault();

            const deleteUrl = $(this).data('url');

            Swal.fire({
                icon: 'warning',
                title: 'Delete this account type?',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete',
                confirmButtonColor: '#dc3545',
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    type: 'DELETE',
                    url: deleteUrl,
                    headers: {
                        'X-CSRF-TOKEN': @json(csrf_token()),
                    },
                }).done(function () {
                    $('#accountTypesTable').DataTable().ajax.reload(null, false);
                }).fail(function (xhr) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Delete blocked',
                        text: xhr.responseJSON?.message || 'Could not delete account type.',
                    });
                });
            });
        });
    </script>
@endsection
