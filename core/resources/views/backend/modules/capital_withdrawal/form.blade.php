<div class="modal-header border-0 px-24 py-16">
    <div>
        <h5 class="modal-title">New Capital Withdrawal</h5>
        <small class="text-muted">Record Owner / Partner money taken from business</small>
    </div>

    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<form method="POST" action="{{ route('finance.capital-withdrawals.store') }}" data-ajax="true">
    @csrf

    <div class="modal-body p-24">
        <div class="alert alert-light-warning border-warning-subtle text-sm py-10 mb-20">
            This does not overwrite original Capital. Posting creates a Journal Draft:
            Debit Drawings and Credit Cash / Bank.
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Branch <span class="text-danger">*</span></label>
                <select
                    name="branch_id"
                    id="withdrawalBranch"
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
                <label class="form-label">Withdrawal Type <span class="text-danger">*</span></label>
                <select name="withdrawal_type" class="form-select form-select-sm js-s2" required>
                    <option value="OWNER_WITHDRAWAL">Owner Withdrawal</option>
                    <option value="PARTNER_WITHDRAWAL">Partner Withdrawal</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Withdrawal Date <span class="text-danger">*</span></label>
                <input
                    type="date"
                    name="withdrawal_date"
                    class="form-control form-control-sm"
                    value="{{ now()->toDateString() }}"
                    required
                >
            </div>

            <div class="col-md-6">
                <label class="form-label">Cash / Bank Paid From <span class="text-danger">*</span></label>
                <select
                    name="cash_bank_account_id"
                    id="withdrawalCash"
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
                <label class="form-label">Drawings Account <span class="text-danger">*</span></label>
                <select
                    name="drawings_account_id"
                    id="drawingsAccount"
                    class="form-select form-select-sm js-s2"
                    required
                >
                    <option value="">Select Equity / Drawings account</option>
                    @foreach ($drawingsAccounts as $account)
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
                <input name="reference_no" class="form-control form-control-sm">
            </div>

            <div class="col-md-4">
                <label class="form-label">Note</label>
                <input name="note" class="form-control form-control-sm">
            </div>
        </div>
    </div>

    <div class="modal-footer border-0 px-24 py-16">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
            Cancel
        </button>
        <button class="btn btn-primary btn-sm app-primary-action d-inline-flex align-items-center gap-1">
            <iconify-icon icon="mdi:content-save-outline"></iconify-icon>
            <span>Save Draft</span>
        </button>
    </div>
</form>

<script>
    (function ($) {
        const modal = $('#AjaxModal');
        const branchAccountsUrl = @json(
            route('finance.capital-withdrawals.branch-accounts', '__branch__')
        );

        S2.auto(modal);

        function accountOption(account) {
            return $('<option>')
                .val(account.id)
                .text(`${account.code} — ${account.name}`);
        }

        modal
            .off('change.withdrawal', '#withdrawalBranch')
            .on('change.withdrawal', '#withdrawalBranch', function () {
                const branchId = $(this).val();

                if (!branchId) {
                    return;
                }

                $.get(branchAccountsUrl.replace('__branch__', branchId), function (response) {
                    const cashSelect = $('#withdrawalCash');
                    const drawingsSelect = $('#drawingsAccount');

                    cashSelect
                        .empty()
                        .append('<option value="">Select Cash / Bank account</option>');
                    drawingsSelect
                        .empty()
                        .append('<option value="">Select Equity / Drawings account</option>');

                    (response.cash_accounts || []).forEach(function (account) {
                        cashSelect.append(accountOption(account));
                    });

                    (response.drawings_accounts || []).forEach(function (account) {
                        drawingsSelect.append(accountOption(account));
                    });
                });
            });
    })(jQuery);
</script>
