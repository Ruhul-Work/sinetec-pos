@php
    $isSystemAccount = $isEdit && $account->is_system;
    $cashBankInputId = $isEdit ? 'accountCashBankEdit' : 'accountCashBank';
    $negativeInputId = $isEdit ? 'accountAllowNegativeEdit' : 'accountAllowNegative';
    $activeInputId = $isEdit ? 'accountActiveEdit' : 'accountActive';
@endphp

<div class="row g-3">
    <div class="col-md-4">
        <label class="form-label">Account Code <span class="text-danger">*</span></label>
        <input
            type="text"
            name="code"
            class="form-control text-uppercase"
            value="{{ $account?->code }}"
            maxlength="50"
            @readonly($isSystemAccount)
            required
        >
        @if ($isSystemAccount)
            <small class="text-muted">Starter account code is protected.</small>
        @endif
    </div>

    <div class="col-md-8">
        <label class="form-label">Account Name <span class="text-danger">*</span></label>
        <input
            type="text"
            name="name"
            class="form-control"
            value="{{ $account?->name }}"
            maxlength="100"
            required
        >
    </div>

    <div class="col-md-6">
        <label class="form-label">Account Type <span class="text-danger">*</span></label>
        <select
            name="account_type_id"
            class="form-control js-s2"
            @disabled($isSystemAccount)
            required
        >
            <option value="">Select type</option>
            @foreach ($types as $type)
                <option
                    value="{{ $type->id }}"
                    @selected($account?->account_type_id === $type->id)
                >
                    {{ $type->name }} ({{ $type->normal_balance->label() }})
                </option>
            @endforeach
        </select>
        @if ($isSystemAccount)
            <input type="hidden" name="account_type_id" value="{{ $account->account_type_id }}">
        @endif
    </div>

    <div class="col-md-6">
        <label class="form-label">Parent Account</label>
        <select
            name="parent_id"
            class="form-control js-s2"
            @disabled($isSystemAccount)
        >
            <option value="">Root account</option>
            @foreach ($parents as $parent)
                <option value="{{ $parent->id }}" @selected($account?->parent_id === $parent->id)>
                    {{ $parent->code }} - {{ $parent->name }}
                </option>
            @endforeach
        </select>
        @if ($isSystemAccount)
            <input type="hidden" name="parent_id" value="{{ $account->parent_id }}">
        @endif
    </div>

    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea
            name="description"
            class="form-control"
            rows="2"
            maxlength="500"
        >{{ $account?->description }}</textarea>
    </div>

    <div class="col-md-6">
        <label class="form-label">Bank Name</label>
        <input
            type="text"
            name="bank_name"
            class="form-control"
            value="{{ $account?->bank_name }}"
            maxlength="100"
        >
    </div>

    <div class="col-md-6">
        <label class="form-label">Bank Account Number</label>
        <input
            type="text"
            name="bank_account_no"
            class="form-control"
            value="{{ $account?->bank_account_no }}"
            maxlength="100"
        >
    </div>

    <div class="col-12">
        <label class="form-label">Bank Details</label>
        <textarea name="bank_details" class="form-control" rows="2">{{ $account?->bank_details }}</textarea>
    </div>

    <div class="col-md-6">
        <div class="form-switch switch-purple d-flex align-items-center gap-3">
            <input type="hidden" name="is_cash_bank_account" value="0">
            <input
                class="form-check-input"
                type="checkbox"
                name="is_cash_bank_account"
                value="1"
                id="{{ $cashBankInputId }}"
                @checked($account?->is_cash_bank_account)
            >
            <label for="{{ $cashBankInputId }}">Use as Cash / Bank Account</label>
        </div>
        <small class="text-muted">
            Only Asset accounts can be selected for supplier payments and Cash/Bank Book.
        </small>
    </div>

    <div class="col-md-6">
        <div class="form-switch switch-purple d-flex align-items-center gap-3">
            <input type="hidden" name="allow_negative" value="0">
            <input
                class="form-check-input"
                type="checkbox"
                name="allow_negative"
                value="1"
                id="{{ $negativeInputId }}"
                @checked($account?->allow_negative)
            >
            <label for="{{ $negativeInputId }}">Allow negative balance</label>
        </div>
    </div>

    <div class="col-md-6">
        <div class="form-switch switch-purple d-flex align-items-center gap-3">
            <input type="hidden" name="is_active" value="0">
            <input
                class="form-check-input"
                type="checkbox"
                name="is_active"
                value="1"
                id="{{ $activeInputId }}"
                @checked($isEdit ? $account->is_active : true)
            >
            <label for="{{ $activeInputId }}">Active</label>
        </div>
    </div>
</div>
