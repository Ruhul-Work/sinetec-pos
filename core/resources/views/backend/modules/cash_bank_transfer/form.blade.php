<div class="modal-header border-0 px-24 py-16">
    <div>
        <h5 class="modal-title">New Cash / Bank Transfer</h5>
        <small class="text-muted">Same-branch Cash to Bank, Bank to Cash or Bank to Bank</small>
    </div>

    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<form action="{{ route('finance.cash-bank-transfers.store') }}" method="POST" data-ajax="true">
    @csrf

    <div class="modal-body p-24">
        <div class="alert alert-light-info border-info-subtle text-sm py-10 mb-20">
            This is not an expense or withdrawal. Source Cash / Bank is credited and destination
            Cash / Bank is debited after its Journal is posted.
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Branch <span class="text-danger">*</span></label>
                <select
                    name="branch_id"
                    id="transferBranch"
                    class="form-select form-select-sm js-s2"
                    required
                    @disabled(current_branch_id())
                >
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($branch->id == $branchId)>
                            {{ $branch->name }} — {{ $branch->code }}
                        </option>
                    @endforeach
                </select>

                @if (current_branch_id())
                    <input type="hidden" name="branch_id" value="{{ current_branch_id() }}">
                @endif
            </div>

            <div class="col-md-4">
                <label class="form-label">Transfer Date <span class="text-danger">*</span></label>
                <input
                    type="date"
                    name="transfer_date"
                    value="{{ now()->toDateString() }}"
                    class="form-control form-control-sm"
                    required
                >
            </div>

            <div class="col-md-4">
                <label class="form-label">Amount <span class="text-danger">*</span></label>
                <input
                    type="number"
                    name="amount"
                    min="0.0001"
                    step="0.0001"
                    class="form-control form-control-sm text-end"
                    required
                >
            </div>

            <div class="col-md-6">
                <label class="form-label">From Cash / Bank <span class="text-danger">*</span></label>
                <select
                    name="from_account_id"
                    id="fromAccount"
                    class="form-select form-select-sm js-s2"
                    required
                >
                    <option value="">Select source account</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">
                            {{ $account->code }} — {{ $account->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">To Cash / Bank <span class="text-danger">*</span></label>
                <select
                    name="to_account_id"
                    id="toAccount"
                    class="form-select form-select-sm js-s2"
                    required
                >
                    <option value="">Select destination account</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">
                            {{ $account->code }} — {{ $account->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Reference No.</label>
                <input name="reference_no" class="form-control form-control-sm">
            </div>

            <div class="col-md-6">
                <label class="form-label">Note</label>
                <input name="note" class="form-control form-control-sm">
            </div>
        </div>
    </div>

    <div class="modal-footer border-0 px-24 py-16">
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
    S2.auto($('#AjaxModal'));
</script>
