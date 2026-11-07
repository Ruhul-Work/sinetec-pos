<div class="modal-header py-16 px-24 border-0">
    <div>
        <h5 class="modal-title">Prepare Fiscal Year Closing</h5><small class="text-muted">Close one Drawings balance into
            its Capital account</small>
    </div><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<form method="POST" action="{{ route('finance.fiscal-year-closings.store') }}" data-ajax="true">
    @csrf
    <div class="modal-body p-24">
        <div class="alert alert-light-warning border-warning-subtle text-sm py-10 mb-20">The system calculates the
            selected Drawings balance from Posted opening balances and Posted journals. The closing date is locked to
            the fiscal year end. Posting this document creates a Journal Draft: <strong>Debit Capital, Credit
                Drawings.</strong></div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Fiscal Year *</label><select name="fiscal_year_id"
                    id="closingFiscalYear" class="form-select form-select-sm js-s2" required>
                    @foreach ($years as $year)
                        <option value="{{ $year->id }}"
                            data-end-date="{{ \Carbon\Carbon::parse($year->end_date)->toDateString() }}">
                            {{ $year->name }} (ends {{ \Carbon\Carbon::parse($year->end_date)->format('d M Y') }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6"><label class="form-label">Branch *</label><select name="branch_id" id="closingBranch"
                    class="form-select form-select-sm js-s2" required @disabled(current_branch_id())>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $branchId === (int) $branch->id)>{{ $branch->name }} —
                            {{ $branch->code }}</option>
                    @endforeach
                </select>
                @if (current_branch_id())
                    <input type="hidden" name="branch_id" value="{{ current_branch_id() }}">
                @endif
            </div>
            <div class="col-md-6"><label class="form-label">Drawings Account *</label><select name="drawings_account_id"
                    id="closingDrawings" class="form-select form-select-sm js-s2" required>
                    <option value="">Select Drawings account</option>
                    @foreach ($equityAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6"><label class="form-label">Capital Account *</label><select name="capital_account_id"
                    id="closingCapital" class="form-select form-select-sm js-s2" required>
                    <option value="">Select Capital account</option>
                    @foreach ($equityAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                    @endforeach
                </select></div>
            <div class="col-md-6"><label class="form-label">Closing Date *</label><input type="date"
                    name="closing_date" id="closingDate" class="form-control form-control-sm"
                    value="{{ \Carbon\Carbon::parse($years->first()->end_date)->toDateString() }}" readonly required>
            </div>
            <div class="col-md-6"><label class="form-label">Calculated Drawings Balance</label>
                <div class="input-group input-group-sm"><span class="input-group-text">BDT</span><input
                        id="closingAmount" class="form-control text-end fw-semibold" value="0.00" readonly></div>
                <small class="text-muted">Only a positive debit balance can be closed.</small>
            </div>
            <div class="col-md-6"><label class="form-label">Reference No.</label><input name="reference_no"
                    class="form-control form-control-sm"></div>
            <div class="col-md-6"><label class="form-label">Note</label><input name="note"
                    class="form-control form-control-sm"></div>
        </div>
    </div>
    <div class="modal-footer px-24 py-16 border-0"><button type="button" class="btn btn-outline-secondary btn-sm"
            data-bs-dismiss="modal">Cancel</button><button
            class="btn btn-primary btn-sm app-primary-action d-inline-flex align-items-center gap-1"><iconify-icon
                icon="mdi:content-save-outline"></iconify-icon><span>Save Draft</span></button></div>
</form>


<script>
    (function($) {
        const modal = $('#AjaxModal');
        const accountUrl = @json(route('finance.fiscal-year-closings.branch-accounts', '__branch__'));
        const balanceUrl = "{{ route('finance.fiscal-year-closings.balance', ['fiscalYear' => '__year__', 'branch' => '__branch__', 'drawingsAccount' => '__account__']) }}";
        const canPrepareClosing = @json($canPrepareClosing);
        const closingAvailableOn = @json($closingAvailableOn);
        S2.auto(modal);
        const option = account => $('<option>').val(account.id).text(account.code + ' — ' + account.name);

        function syncClosingDate() {
            $('#closingDate').val($('#closingFiscalYear option:selected').data('end-date') || '');
        }

        function loadAccounts() {
            const branch = $('#closingBranch').val();
            if (!branch) return;
            $.get(accountUrl.replace('__branch__', branch), function(data) {
                $('#closingDrawings, #closingCapital').each(function() {
                    $(this).empty().append('<option value="">Select ' + (this.id ===
                        'closingDrawings' ? 'Drawings' : 'Capital') + ' account</option>');
                    (data.equity_accounts || []).forEach(account => $(this).append(option(
                    account)));
                }).trigger('change.select2');
                loadBalance();
            });
        }

        function loadBalance() {
            const year = $('#closingFiscalYear').val(),
                branch = $('#closingBranch').val(),
                account = $('#closingDrawings').val();
            if (!year || !branch || !account) return $('#closingAmount').val('0.00');
            $.get(balanceUrl.replace('__year__', year).replace('__branch__', branch).replace('__account__',
                account), data => $('#closingAmount').val(data.formatted || '0.00'));
        }
        modal.off('change.fiscalClosing', '#closingFiscalYear').on('change.fiscalClosing', '#closingFiscalYear',
            function() {
                syncClosingDate();
                loadBalance();
            });
        modal.off('change.fiscalClosingBranch', '#closingBranch').on('change.fiscalClosingBranch', '#closingBranch',
            loadAccounts);
        modal.off('change.fiscalClosingDrawings', '#closingDrawings').on('change.fiscalClosingDrawings',
            '#closingDrawings', loadBalance);
        modal.find('form[data-ajax="true"]').off('submit.fiscalClosingGuard').on('submit.fiscalClosingGuard', function(event) {
            if (canPrepareClosing) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            Swal.fire({
                icon: 'warning',
                title: 'Fiscal Year Closing is not available yet',
                text: 'Closing can be created on or after ' + closingAvailableOn + '. The current fiscal year is still open.',
                confirmButtonText: 'Okay'
            });
        });
        syncClosingDate();
    })(jQuery);
</script>
