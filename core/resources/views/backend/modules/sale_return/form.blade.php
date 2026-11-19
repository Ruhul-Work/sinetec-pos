@extends('backend.layouts.master')

@php
    $editing = (bool) $saleReturn;
    $oldItems = collect(old('items', []))->keyBy(fn ($item) => (int) ($item['sale_item_id'] ?? 0));
@endphp

@section('meta')
    <title>{{ $editing ? 'Edit' : 'Create' }} Sales Return</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">{{ $editing ? 'Edit Sales Return' : 'Create Sales Return' }}</h6>
                <x-backend.page-note title="Before saving" label="View Draft rules" align="start">
                    Select a Posted Sale and enter only the quantity received back from the customer. Original unit and
                    rate are server-controlled. Saving a Draft does not increase stock or create a journal.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">{{ $editing ? $saleReturn->return_no : 'Prepare a return against an original invoice' }}</p>
        </div>
        <a href="{{ route('sales.returns.index') }}"
            class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button">
            <iconify-icon icon="mdi:arrow-left"></iconify-icon>
            <span>Back to List</span>
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>Please correct the highlighted information.</strong>
            <ul class="mb-0 mt-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
        action="{{ $editing ? route('sales.returns.update', $saleReturn) : route('sales.returns.store') }}"
        id="saleReturnForm">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <div class="card mb-24">
            <div class="card-header">
                <h5 class="card-title mb-0">Return Information</h5>
            </div>
            <div class="card-body">
                <div class="row gy-3 align-items-end">
                    <div class="col-lg-6">
                        <label class="form-label">Source Sale <span class="text-danger">*</span></label>
                        @if ($editing)
                            <input type="hidden" name="sale_id" value="{{ $sale->id }}">
                            <input type="text" class="form-control form-control-sm"
                                value="{{ $sale->sale_no }} — {{ $sale->channel->label() }} — {{ $sale->customer?->name ?: 'Walk-in Customer' }}"
                                readonly>
                        @else
                            <select name="sale_id" id="sourceSale" class="form-control form-control-sm js-s2"
                                placeholder="Select a Posted Sale" required>
                                <option value=""></option>
                                @foreach ($sales as $candidate)
                                    <option value="{{ $candidate->id }}"
                                        @selected((string) old('sale_id', $sale?->id) === (string) $candidate->id)>
                                        {{ $candidate->sale_no }} — {{ $candidate->channel->label() }} —
                                        {{ $candidate->customer?->name ?: 'Walk-in Customer' }} —
                                        {{ $candidate->sale_at->format('d M Y') }}
                                    </option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Return Date & Time <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="return_at" class="form-control form-control-sm"
                            value="{{ old('return_at', $saleReturn?->return_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}"
                            max="{{ now()->format('Y-m-d\TH:i') }}" required>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Return Reason <span class="text-danger">*</span></label>
                        <input type="text" name="reason" class="form-control form-control-sm" maxlength="5000"
                            value="{{ old('reason', $saleReturn?->reason) }}"
                            placeholder="Damaged, wrong item, customer return..." required>
                    </div>
                </div>
            </div>
        </div>

        @if ($sale)
            <div class="card mb-24">
                <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                    <div>
                        <h5 class="card-title mb-1">Source Invoice Summary</h5>
                        <p class="text-muted text-sm mb-0">Original invoice information is read-only</p>
                    </div>
                    <span class="badge bg-primary-focus text-primary-main radius-4 px-12 py-7">
                        {{ $sale->channel->label() }}
                    </span>
                </div>
                <div class="card-body">
                    <div class="row gy-3">
                        <div class="col-md-3">
                            <small class="text-muted d-block">Invoice</small>
                            <strong>{{ $sale->sale_no }}</strong>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted d-block">Customer</small>
                            <strong>{{ $sale->customer?->name ?: 'Walk-in Customer' }}</strong>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted d-block">Warehouse</small>
                            <strong>{{ $sale->warehouse->name }}</strong>
                            <span class="text-muted">({{ $sale->warehouse->code }})</span>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted d-block">Sale Date</small>
                            <strong>{{ $sale->sale_at->format('d M Y, h:i A') }}</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-1">Return Items</h5>
                    <p class="text-muted text-sm mb-0">Select only the invoice lines being returned</p>
                </div>
                <div class="card-body">
                    @if (count($sourceItems) === 0)
                        <div class="alert alert-warning mb-0">
                            Every line of this Sale has already been returned. Select another Sale.
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table bordered-table align-middle mb-0" id="saleReturnLines"
                                data-sale-subtotal="{{ $sale->subtotal }}"
                                data-sale-discount="{{ $sale->discount }}"
                                data-sale-tax="{{ $sale->tax }}">
                                <thead>
                                    <tr>
                                        <th class="text-center" style="width: 55px">Return</th>
                                        <th>Finished Product</th>
                                        <th>Sale Unit</th>
                                        <th class="text-end">Sold Qty</th>
                                        <th class="text-end">Returnable</th>
                                        <th class="text-end">Original Rate</th>
                                        <th style="width: 170px">Return Qty</th>
                                        <th class="text-end">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($sourceItems as $index => $item)
                                        @php
                                            $oldItem = $oldItems->get($item['sale_item_id']);
                                            $quantity = $oldItem['quantity'] ?? $item['selected_quantity'];
                                            $selected = $oldItem !== null || (float) $item['selected_quantity'] > 0;
                                            $step = $item['unit_precision'] === 0
                                                ? '1'
                                                : '0.' . str_repeat('0', $item['unit_precision'] - 1) . '1';
                                            $maximumMessage = 'Maximum returnable quantity is '
                                                . $item['available_quantity'] . ' ' . $item['unit_code'] . '.';
                                        @endphp
                                        <tr class="js-return-line" data-unit-price="{{ $item['unit_price'] }}">
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input js-return-toggle"
                                                    @checked($selected)>
                                            </td>
                                            <td>
                                                <strong class="d-block">{{ $item['name'] }}</strong>
                                                <code>{{ $item['code'] }}</code>
                                                <input type="hidden" name="items[{{ $index }}][sale_item_id]"
                                                    value="{{ $item['sale_item_id'] }}" class="js-return-field"
                                                    @disabled(! $selected)>
                                            </td>
                                            <td>{{ $item['unit_text'] }}</td>
                                            <td class="text-end">{{ $item['sold_quantity'] }}</td>
                                            <td class="text-end fw-semibold text-success-main">
                                                {{ $item['available_quantity'] }} {{ $item['unit_code'] }}
                                            </td>
                                            <td class="text-end">{{ number_format((float) $item['unit_price'], 2) }}</td>
                                            <td>
                                                <input type="number" name="items[{{ $index }}][quantity]"
                                                    class="form-control form-control-sm text-center js-return-quantity js-return-field"
                                                    value="{{ $selected ? $quantity : '' }}" min="{{ $step }}"
                                                    max="{{ $item['available_quantity_raw'] }}" step="{{ $step }}"
                                                    data-maximum-message="{{ $maximumMessage }}"
                                                    @disabled(! $selected) required>
                                                <div class="invalid-feedback js-return-quantity-error"></div>
                                            </td>
                                            <td class="text-end fw-semibold js-line-total">0.00</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="row justify-content-end mt-4">
                            <div class="col-xl-4 col-lg-5">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span>Return Subtotal</span>
                                    <strong id="returnSubtotal">0.00</strong>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span>Discount Reversal</span>
                                    <strong id="returnDiscount">0.00</strong>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span>Tax Reversal</span>
                                    <strong id="returnTax">0.00</strong>
                                </div>
                                <div class="d-flex justify-content-between align-items-center border-top pt-3">
                                    <strong>Draft Return Total</strong>
                                    <strong class="text-danger-main fs-6" id="returnTotal">0.00</strong>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="card-footer d-flex align-items-center justify-content-end gap-2">
                    <a href="{{ route('sales.returns.index') }}"
                        class="btn btn-outline-secondary px-18 py-9 radius-8 app-action-button">
                        <span>Cancel</span>
                    </a>
                    <button type="submit"
                        class="btn btn-primary px-18 py-9 radius-8 app-action-button app-primary-action js-save-return"
                        @disabled(count($sourceItems) === 0)>
                        <iconify-icon icon="mdi:content-save-outline"></iconify-icon>
                        <span>Save Draft</span>
                    </button>
                </div>
            </div>
        @else
            <div class="card">
                <div class="card-body py-5 text-center">
                    <iconify-icon icon="mdi:receipt-text-search-outline" class="text-primary-600 mb-2"
                        style="font-size: 48px"></iconify-icon>
                    <h6 class="mb-1">Select a Posted Sale</h6>
                    <p class="text-muted mb-0">The original invoice items and returnable quantities will appear here.</p>
                </div>
            </div>
        @endif
    </form>
@endsection

@section('script')
    <script>
        (function($) {
            S2.auto($('#saleReturnForm'));

            $('#sourceSale').on('change', function() {
                const saleId = $(this).val();
                const url = new URL(@json(route('sales.returns.create')), window.location.origin);

                if (saleId) {
                    url.searchParams.set('sale_id', saleId);
                }

                window.location.href = url.toString();
            });

            function validateQuantity($input) {
                const input = $input.get(0);

                if (!input || input.disabled) {
                    $input.removeClass('is-invalid');
                    $input.siblings('.js-return-quantity-error').text('');
                    input?.setCustomValidity('');

                    return true;
                }

                const rawQuantity = String($input.val() ?? '').trim();
                const quantity = Number(rawQuantity);
                const minimum = Number($input.attr('min'));
                const maximum = Number($input.attr('max'));
                let message = '';

                if (rawQuantity === '' || !Number.isFinite(quantity)) {
                    message = 'Enter the quantity being returned.';
                } else if (quantity < minimum) {
                    message = `Return quantity must be at least ${minimum}.`;
                } else if (quantity > maximum) {
                    message = $input.data('maximumMessage');
                }

                input.setCustomValidity(message);
                $input.toggleClass('is-invalid', message !== '');
                $input.siblings('.js-return-quantity-error').text(message);

                return message === '';
            }

            function recalculate() {
                let subtotal = 0;
                let hasSelectedLine = false;
                let quantitiesAreValid = true;

                $('.js-return-line').each(function() {
                    const $line = $(this);
                    const selected = $line.find('.js-return-toggle').is(':checked');
                    const $quantityInput = $line.find('.js-return-quantity');
                    const quantityIsValid = validateQuantity($quantityInput);
                    const quantity = selected && quantityIsValid
                        ? Number($quantityInput.val() || 0)
                        : 0;
                    const unitPrice = Number($line.data('unitPrice') || 0);
                    const lineTotal = quantity * unitPrice;

                    hasSelectedLine = hasSelectedLine || selected;
                    quantitiesAreValid = quantitiesAreValid && quantityIsValid;
                    $line.find('.js-line-total').text(lineTotal.toFixed(2));
                    subtotal += lineTotal;
                });

                const $lines = $('#saleReturnLines');
                const saleSubtotal = Number($lines.data('saleSubtotal')) || 0;
                const saleDiscount = Number($lines.data('saleDiscount')) || 0;
                const saleTax = Number($lines.data('saleTax')) || 0;
                const ratio = saleSubtotal > 0 ? Math.min(subtotal / saleSubtotal, 1) : 0;
                const discount = saleDiscount * ratio;
                const tax = saleTax * ratio;
                const total = subtotal - discount + tax;

                $('#returnSubtotal').text(subtotal.toFixed(2));
                $('#returnDiscount').text(discount.toFixed(2));
                $('#returnTax').text(tax.toFixed(2));
                $('#returnTotal').text(total.toFixed(2));
                $('.js-save-return').prop('disabled', !hasSelectedLine || !quantitiesAreValid);
            }

            $('.js-return-toggle').on('change', function() {
                const $line = $(this).closest('.js-return-line');
                const selected = $(this).is(':checked');

                $line.find('.js-return-field').prop('disabled', !selected);
                if (selected && !$line.find('.js-return-quantity').val()) {
                    $line.find('.js-return-quantity').val($line.find('.js-return-quantity').attr('min'));
                }

                recalculate();
            });

            $('.js-return-quantity').on('input change', recalculate);

            $('#saleReturnForm').on('submit', function(event) {
                recalculate();

                const invalidInput = this.querySelector('.js-return-quantity:invalid');
                if (invalidInput) {
                    event.preventDefault();
                    invalidInput.reportValidity();
                    invalidInput.focus();
                }
            });

            recalculate();
        })(jQuery);
    </script>
@endsection
