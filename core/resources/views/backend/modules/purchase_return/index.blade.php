@extends('backend.layouts.master')
@section('meta')
    <title>Purchase Returns</title>
@endsection
@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Purchase Returns</h6><x-backend.page-note title="Stock return posting"
                    label="View return rules" align="start">A return must reference a posted Purchase Receipt. Draft returns
                    do not affect stock. Posting permanently removes the returned quantity from the receipt warehouse;
                    posted returns cannot be edited or deleted.</x-backend.page-note>
            </div>
            <p class="text-muted m-0">Send received raw materials or parts back to their supplier</p>
        </div>
    </div>
    <div class="card basic-data-table">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <h5 class="card-title mb-0">Return Documents</h5>
            <div class="actions-bar d-flex align-items-center gap-2 flex-wrap"><select id="purchaseReturnStatusFilter"
                    class="form-select form-select-sm w-auto">
                    <option value="">All status</option>
                    <option value="DRAFT">Draft</option>
                    <option value="POSTED">Posted</option>
                </select>
                <div class="search-set me-2">
                    <div id="tableSearch" class="search-input"></div>
                </div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">@include('backend.include.buttons')
                </ul>
                @perm('purchase.returns.store', 'add')
                    <a href="{{ route('purchase.returns.create') }}"
                        class="btn btn-primary btn-sm px-12 py-8 radius-8 app-action-button"><iconify-icon
                            icon="ic:baseline-plus"></iconify-icon><span>Add Return</span></a>
                @endperm
            </div>
        </div>
        <div class="card-body">
            <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="purchaseReturnTable" style="width:100%">
                <thead>
                    <tr>
                        <th>S.L</th>
                        <th>Return</th>
                        <th>Date</th>
                        <th>Supplier</th>
                        <th>Warehouse</th>
                        <th>Source Receipt</th>
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
        var DATATABLE_URL = @json(route('purchase.returns.list.ajax'));
        $(document).on('preXhr.dt', '#purchaseReturnTable', function(e, s, d) {
            d.status = $('#purchaseReturnStatusFilter').val();
        });
        $('#purchaseReturnStatusFilter').on('change', function() {
            if ($.fn.DataTable.isDataTable('#purchaseReturnTable')) $('#purchaseReturnTable').DataTable().ajax
                .reload();
        });
        $(document).on('click', '.btn-purchase-return-delete', function(e) {
            e.preventDefault();
            const url = $(this).data('url'),
                remove = () => $.ajax({
                    url,
                    type: 'POST',
                    data: {
                        _method: 'DELETE',
                        _token: @json(csrf_token())
                    },
                    success: r => {
                        $('#purchaseReturnTable').DataTable().ajax.reload(null, false);
                        Swal.fire({
                            icon: 'success',
                            title: r.msg,
                            timer: 1200,
                            showConfirmButton: false
                        });
                    },
                    error: x => Swal.fire({
                        icon: 'error',
                        title: 'Failed',
                        text: x.responseJSON?.msg || 'Delete failed.'
                    })
                });
            Swal.fire({
                icon: 'warning',
                title: 'Delete this draft?',
                text: 'This removes the unposted purchase return.',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete',
                confirmButtonColor: '#dc3545'
            }).then(r => {
                if (r.isConfirmed) remove();
            });
        });
    </script>
@endsection
