<div class="modal-header py-16 px-24 border-0">
    <div class="d-flex align-items-center gap-2">
        <h5 class="modal-title mb-0">New Owner / Partner Investment</h5>
        <x-backend.field-note label="Investment entry information">
            Record business funds received after Opening Balance. The document starts as Draft; posting it creates
            a Finance Journal Draft that debits Cash / Bank and credits Capital.
        </x-backend.field-note>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<form method="POST" action="{{ route('finance.capital-investments.store') }}" data-ajax="true">
    @csrf

    <div class="modal-body p-24">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Branch <span class="text-danger">*</span></label>
                <select
                    name="branch_id"
                    id="investmentBranch"
                    class="form-select form-select-sm js-s2"
                    @disabled(current_branch_id())
                    required
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
                <label class="form-label">Investment Type <span class="text-danger">*</span></label>
                <select name="investment_type" class="form-select form-select-sm js-s2" required>
                    <option value="OWNER_CAPITAL" @selected(old('investment_type') === 'OWNER_CAPITAL')>
                        Owner Capital
                    </option>
                    <option value="PARTNER_CAPITAL" @selected(old('investment_type') === 'PARTNER_CAPITAL')>
                        Partner Capital
                    </option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Investment Date <span class="text-danger">*</span></label>
                <input
                    type="date"
                    name="investment_date"
                    class="form-control form-control-sm"
                    value="{{ old('investment_date', now()->toDateString()) }}"
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
                    id="cashBankAccount"
                    class="form-select form-select-sm js-s2"
                    required
                >
                    <option value="">Select Cash / Bank account</option>
                    @foreach ($cashAccounts as $account)
                        <option
                            value="{{ $account->id }}"
                            @selected((int) old('cash_bank_account_id') === (int) $account->id)
                        >
                            {{ $account->code }} — {{ $account->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-6">
                <div class="d-flex align-items-center gap-1 mb-8">
                    <label class="form-label mb-0">Capital Account <span class="text-danger">*</span></label>
                    <x-backend.field-note label="Capital account selection">
                        Use Owner / Partner Capital or another Equity account assigned to the selected branch.
                    </x-backend.field-note>
                </div>
                <select
                    name="capital_account_id"
                    id="capitalAccount"
                    class="form-select form-select-sm js-s2"
                    required
                >
                    <option value="">Select Equity account</option>
                    @foreach ($capitalAccounts as $account)
                        <option
                            value="{{ $account->id }}"
                            @selected((int) old('capital_account_id') === (int) $account->id)
                        >
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
                    value="{{ old('amount') }}"
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
                    value="{{ old('reference_no') }}"
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
                    value="{{ old('note') }}"
                    placeholder="Optional note"
                >
            </div>
        </div>
    </div>

    <div class="modal-footer px-24 py-16 border-0 d-flex justify-content-end gap-2">
        <button
            type="button"
            class="btn btn-outline-secondary btn-sm px-16 py-8 radius-8 app-action-button"
            data-bs-dismiss="modal"
        >
            Cancel
        </button>
        <button
            type="submit"
            class="btn btn-primary btn-sm px-18 py-8 radius-8 app-primary-action d-inline-flex align-items-center gap-1"
        >
            <iconify-icon icon="mdi:content-save-outline"></iconify-icon>
            <span>Save Draft</span>
        </button>
    </div>
</form>

<script>
    (function ($) {
        const $modal = $('#AjaxModal');
        const accountsUrl = @json(route('finance.capital-investments.branch-accounts', '__branch__'));

        function createAccountOption(account) {
            return $('<option>')
                .val(account.id)
                .text(`${account.code} — ${account.name}`);
        }

        function replaceAccountOptions(selector, placeholder, accounts) {
            const $select = $(selector);

            $select.empty().append($('<option>').val('').text(placeholder));
            accounts.forEach(function (account) {
                $select.append(createAccountOption(account));
            });
            $select.trigger('change');
        }

        S2.auto($modal);

        $modal
            .off('change.capitalInvestment', '#investmentBranch')
            .on('change.capitalInvestment', '#investmentBranch', function () {
                const branchId = $(this).val();

                if (!branchId) {
                    return;
                }

                $.get(accountsUrl.replace('__branch__', branchId), function (response) {
                    replaceAccountOptions(
                        '#cashBankAccount',
                        'Select Cash / Bank account',
                        response.cash_accounts || [],
                    );
                    replaceAccountOptions(
                        '#capitalAccount',
                        'Select Equity account',
                        response.capital_accounts || [],
                    );
                });
            });
    })(jQuery);
</script>
