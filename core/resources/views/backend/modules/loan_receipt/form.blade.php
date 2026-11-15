<div class="modal-header border-0 px-24 py-16">
    <div class="d-flex align-items-center gap-2">
        <h5 class="modal-title mb-0">New Director / Partner Loan</h5>
        <x-backend.field-note label="Loan receipt information">
            Record temporary money received after Opening Balance. This is a Liability, not Capital. Posting creates
            a Finance Journal Draft that debits Cash / Bank and credits Loan Payable.
        </x-backend.field-note>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<form method="POST" action="{{ route('finance.loan-receipts.store') }}" data-ajax="true">
    @csrf

    <div class="modal-body p-24">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Branch <span class="text-danger">*</span></label>
                <select
                    name="branch_id"
                    id="loanBranch"
                    class="form-select form-select-sm js-s2"
                    required
                    @disabled(current_branch_id())
                >
                    @foreach ($branches as $branch)
                        <option
                            value="{{ $branch->id }}"
                            @selected((int) old('branch_id', $branchId) === (int) $branch->id)
                        >
                            {{ $branch->name }} — {{ $branch->code }}
                        </option>
                    @endforeach
                </select>

                @if (current_branch_id())
                    <input type="hidden" name="branch_id" value="{{ current_branch_id() }}">
                @endif
            </div>

            <div class="col-md-4">
                <label class="form-label">Loan Type <span class="text-danger">*</span></label>
                <select name="loan_type" class="form-select form-select-sm js-s2" required>
                    <option value="DIRECTOR_LOAN">Director Loan</option>
                    <option value="PARTNER_LOAN">Partner Loan</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Received Date <span class="text-danger">*</span></label>
                <input
                    type="date"
                    name="receipt_date"
                    class="form-control form-control-sm"
                    value="{{ old('receipt_date', now()->toDateString()) }}"
                    required
                >
            </div>

            <div class="col-md-6">
                <div class="d-flex align-items-center gap-1 mb-8">
                    <label class="form-label mb-0">Cash / Bank Received Into <span class="text-danger">*</span></label>
                    <x-backend.field-note label="Available Cash and Bank accounts">
                        Only Cash / Bank accounts assigned to the selected branch appear.
                    </x-backend.field-note>
                </div>
                <select
                    name="cash_bank_account_id"
                    id="loanCashBankAccount"
                    class="form-select form-select-sm js-s2"
                    required
                >
                    <option value="">Select Cash / Bank account</option>
                    @foreach ($cashAccounts as $account)
                        <option value="{{ $account->id }}">
                            {{ $account->code }} — {{ $account->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-6">
                <div class="d-flex align-items-center gap-1 mb-8">
                    <label class="form-label mb-0">Loan Liability Account <span class="text-danger">*</span></label>
                    <x-backend.field-note label="Loan account selection">
                        Use a Director / Partner Loan Liability account assigned to the selected branch.
                    </x-backend.field-note>
                </div>
                <select
                    name="loan_account_id"
                    id="loanAccount"
                    class="form-select form-select-sm js-s2"
                    required
                >
                    <option value="">Select Liability account</option>
                    @foreach ($loanAccounts as $account)
                        <option value="{{ $account->id }}">
                            {{ $account->code }} — {{ $account->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Amount <span class="text-danger">*</span></label>
                <input
                    type="number"
                    name="amount"
                    class="form-control form-control-sm text-end"
                    min="0.0001"
                    step="0.0001"
                    required
                >
            </div>
            <div class="col-md-4">
                <label class="form-label">Reference No.</label>
                <input
                    type="text"
                    name="reference_no"
                    class="form-control form-control-sm"
                    maxlength="100"
                    placeholder="Cheque, bank reference, etc."
                >
            </div>
            <div class="col-md-4">
                <label class="form-label">Note</label>
                <input
                    type="text"
                    name="note"
                    class="form-control form-control-sm"
                    maxlength="5000"
                    placeholder="Optional note"
                >
            </div>
        </div>
    </div>

    <div class="modal-footer border-0 px-24 py-16 d-flex justify-content-end gap-2">
        <button
            type="button"
            class="btn btn-outline-secondary btn-sm px-16 py-8 radius-8 app-action-button"
            data-bs-dismiss="modal"
        >
            Cancel
        </button>
        <button class="btn btn-primary btn-sm px-18 py-8 radius-8 app-primary-action d-inline-flex align-items-center gap-1">
            <iconify-icon icon="mdi:content-save-outline"></iconify-icon>
            <span>Save Draft</span>
        </button>
    </div>
</form>

<script>
    (function ($) {
        const modal = $('#AjaxModal');
        const branchAccountsUrl = @json(route('finance.loan-receipts.branch-accounts', '__branch__'));

        S2.auto(modal);

        function accountOption(account) {
            return $('<option>')
                .val(account.id)
                .text(`${account.code} — ${account.name}`);
        }

        modal
            .off('change.loanReceipt', '#loanBranch')
            .on('change.loanReceipt', '#loanBranch', function () {
                const branchId = $(this).val();
                if (!branchId) {
                    return;
                }

                $.get(branchAccountsUrl.replace('__branch__', branchId), function (response) {
                    const cashSelect = $('#loanCashBankAccount');
                    const loanSelect = $('#loanAccount');

                    cashSelect.empty().append('<option value="">Select Cash / Bank account</option>');
                    loanSelect.empty().append('<option value="">Select Liability account</option>');

                    (response.cash_accounts || []).forEach(function (account) {
                        cashSelect.append(accountOption(account));
                    });
                    (response.loan_accounts || []).forEach(function (account) {
                        loanSelect.append(accountOption(account));
                    });

                    cashSelect.add(loanSelect).trigger('change');
                });
            });
    })(jQuery);
</script>
