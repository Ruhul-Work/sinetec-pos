@php
    $isSystemType = $isEdit && $type->is_system;
@endphp

<div class="mb-3">
    <label class="form-label">Type Name <span class="text-danger">*</span></label>
    <input
        type="text"
        name="name"
        class="form-control"
        value="{{ $type?->name }}"
        required
    >
</div>

<div class="mb-3">
    <div class="d-flex align-items-center gap-1 mb-8">
        <label class="form-label mb-0">Code <span class="text-danger">*</span></label>
        @if ($isSystemType)
            <x-backend.field-note label="Protected account type code">
                System type code is protected.
            </x-backend.field-note>
        @endif
    </div>
    <input
        type="text"
        name="code"
        class="form-control text-uppercase"
        value="{{ $type?->code }}"
        maxlength="20"
        placeholder="Example: ASSET"
        @readonly($isSystemType)
        required
    >
</div>

<div class="mb-3">
    <label class="form-label">Normal Balance <span class="text-danger">*</span></label>
    <select
        name="normal_balance"
        class="form-control js-s2"
        @disabled($isSystemType)
        required
    >
        @foreach ($normalBalances as $balance)
            <option
                value="{{ $balance->value }}"
                @selected($type?->normal_balance === $balance)
            >
                {{ $balance->label() }}
            </option>
        @endforeach
    </select>
    @if ($isSystemType)
        <input type="hidden" name="normal_balance" value="{{ $type->normal_balance->value }}">
    @endif
</div>
