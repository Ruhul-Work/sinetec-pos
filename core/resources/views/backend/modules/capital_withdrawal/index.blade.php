@extends('backend.layouts.master')

@section('meta')
    <title>Capital Withdrawals</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Capital Withdrawals</h6>
                <x-backend.page-note
                    title="Withdrawal accounting rule"
                    label="View withdrawal rule"
                    align="start"
                >
                    This keeps original Capital separate. It debits Owner / Partner Drawings and
                    credits Cash / Bank. Drawings are closed against Capital at financial-year close.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Owner or Partner money withdrawn from business Cash / Bank</p>
        </div>

        @perm('finance.capital-withdrawals.create', 'add')
            <button
                type="button"
                class="btn btn-primary app-primary-action d-inline-flex align-items-center gap-1 px-14 py-9 radius-8 AjaxModal"
                data-ajax-modal="{{ route('finance.capital-withdrawals.create') }}"
                data-size="lg"
                data-onsuccess="CapitalWithdrawalsIndex.onSaved"
            >
                <iconify-icon icon="mdi:cash-minus"></iconify-icon>
                <span>New Withdrawal</span>
            </button>
        @endperm
    </div>

    <div class="card basic-data-table">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <h5 class="card-title mb-0">Withdrawal Documents</h5>
            <div class="actions-bar d-flex align-items-center gap-2">
                <div class="search-set me-2">
                    <div id="tableSearch" class="search-input"></div>
                </div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
                    @include('backend.include.buttons')
                </ul>
            </div>
        </div>

        <div class="card-body">
            <table
                class="table bordered-table table-scroll mb-0 AjaxDataTable"
                id="capitalWithdrawalTable"
                style="width: 100%"
            >
                <thead>
                    <tr>
                        <th>S.L</th>
                        <th>Withdrawal No</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Branch</th>
                        <th>Cash / Bank</th>
                        <th>Drawings Account</th>
                        <th class="text-end">Amount</th>
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
        var DATATABLE_URL = @json(route('finance.capital-withdrawals.list.ajax'));

        window.CapitalWithdrawalsIndex = {
            onSaved: function (response) {
                if (response && response.redirect_url) {
                    window.location.href = response.redirect_url;
                }
            }
        };
    </script>
@endsection
