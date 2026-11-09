@extends('backend.layouts.master')

@section('meta')
    <title>Cash / Bank Transfer</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Cash / Bank Transfer</h6>
                <x-backend.page-note title="Transfer rule" label="View transfer rule" align="start">
                    Use this only within one Branch: Cash to Bank, Bank to Cash or Bank to Bank.
                    Total business money does not change; destination is Debited and source is Credited.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Move money between assigned Cash / Bank accounts in one Branch</p>
        </div>

        @perm('finance.cash-bank-transfers.create', 'add')
            <button
                type="button"
                class="btn btn-primary app-primary-action d-inline-flex align-items-center gap-1 px-14 py-9 radius-8 AjaxModal"
                data-ajax-modal="{{ route('finance.cash-bank-transfers.create') }}"
                data-size="lg"
                data-onsuccess="CashBankTransfers.onSaved"
            >
                <iconify-icon icon="mdi:bank-transfer"></iconify-icon>
                <span>New Transfer</span>
            </button>
        @endperm
    </div>

    <div class="card basic-data-table">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <h5 class="card-title mb-0">Transfer Documents</h5>
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
                id="transferTable"
                style="width: 100%"
            >
                <thead>
                    <tr>
                        <th>S.L</th>
                        <th>Transfer No</th>
                        <th>Date</th>
                        <th>Branch</th>
                        <th>From Account</th>
                        <th>To Account</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
@endsection

@section('script')
    <script>
        var DATATABLE_URL = @json(route('finance.cash-bank-transfers.list.ajax'));

        window.CashBankTransfers = {
            onSaved: function (response) {
                if (response && response.redirect_url) {
                    window.location.href = response.redirect_url;
                }
            }
        };
    </script>
@endsection
