@extends('backend.layouts.master')

@section('meta')
    <title>Sales Returns</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Sales Returns</h6>
                <x-backend.page-note title="Sales Return flow" label="View return rules" align="start">
                    Create a Draft against a Posted POS or Wholesale Sale. The original selling unit and rate remain
                    locked. A Draft does not change stock or Finance. Posting returns stock and adjusts customer and
                    Finance balances in one controlled transaction.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Prepare customer returns against original Sales invoices</p>
        </div>
    </div>

    <div class="card basic-data-table">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <h5 class="card-title mb-0">Return Documents</h5>
            <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
                <select id="saleReturnStatusFilter" class="form-select form-select-sm w-auto">
                    <option value="">All status</option>
                    <option value="DRAFT">Draft</option>
                    <option value="POSTED">Posted</option>
                    <option value="VOID">Void</option>
                </select>
                <div class="search-set me-2">
                    <div id="tableSearch" class="search-input"></div>
                </div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
                    @include('backend.include.buttons')
                </ul>
                @perm('sales.returns.store', 'add')
                    <a href="{{ route('sales.returns.create') }}"
                        class="btn btn-primary btn-sm px-12 py-8 radius-8 app-action-button app-primary-action">
                        <iconify-icon icon="ic:baseline-plus"></iconify-icon>
                        <span>Add Sales Return</span>
                    </a>
                @endperm
            </div>
        </div>

        <div class="card-body">
            <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="saleReturnTable" style="width: 100%">
                <thead>
                    <tr>
                        <th>S.L</th>
                        <th>Return</th>
                        <th>Date & Time</th>
                        <th>Channel</th>
                        <th>Customer</th>
                        <th>Source Sale</th>
                        <th>Warehouse</th>
                        <th>Lines</th>
                        <th>Total</th>
                        <th>Status</th>
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
        var DATATABLE_URL = @json(route('sales.returns.list.ajax'));

        $(document).on('preXhr.dt', '#saleReturnTable', function(event, settings, data) {
            data.status = $('#saleReturnStatusFilter').val();
        });

        $('#saleReturnStatusFilter').on('change', function() {
            if ($.fn.DataTable.isDataTable('#saleReturnTable')) {
                $('#saleReturnTable').DataTable().ajax.reload();
            }
        });

        $(document).on('click', '.btn-sale-return-delete', function(event) {
            event.preventDefault();
            const url = $(this).data('url');

            Swal.fire({
                icon: 'warning',
                title: 'Delete this Draft?',
                text: 'This removes the unposted Sales Return.',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete',
                confirmButtonColor: '#dc3545'
            }).then(function(result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: url,
                    type: 'POST',
                    data: {
                        _method: 'DELETE',
                        _token: @json(csrf_token())
                    },
                    success: function(response) {
                        $('#saleReturnTable').DataTable().ajax.reload(null, false);
                        Swal.fire({
                            icon: 'success',
                            title: response.msg,
                            timer: 1200,
                            showConfirmButton: false
                        });
                    },
                    error: function(xhr) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Delete failed',
                            text: xhr.responseJSON?.msg || 'The Draft could not be deleted.'
                        });
                    }
                });
            });
        });
    </script>
@endsection
