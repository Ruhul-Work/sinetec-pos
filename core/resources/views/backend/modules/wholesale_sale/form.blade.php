@extends('backend.layouts.master')

@php
  $editing = (bool) $sale;
  $submittedItems = old('items', $items);
  $submittedItems = is_array($submittedItems) ? $submittedItems : [];
  $selectedWarehouseId = old('warehouse_id', $sale?->warehouse_id ?? current_warehouse_id());
@endphp

@section('meta')
  <title>{{ $editing ? 'Edit' : 'New' }} Wholesale Sale</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">{{ $editing ? 'Edit' : 'Create' }} Wholesale Sale</h6>
        <x-backend.page-note title="Wholesale Draft" label="View Wholesale Sale information" align="start">
          Use the compact product search to prepare a customer invoice. Save Draft has no stock or Finance
          effect. Continue to Review prepares payment details before final confirmation.
        </x-backend.page-note>
      </div>
      <p class="text-muted mb-0">Customer terms, negotiated rates and Finished Product quantities</p>
    </div>
    <a href="{{ route('sales.wholesale.index') }}"
       class="btn btn-outline-secondary px-14 py-8 radius-8 app-action-button">
      <iconify-icon icon="mdi:arrow-left"></iconify-icon>
      <span>Back to list</span>
    </a>
  </div>

  @if ($errors->any())
    <div class="alert alert-danger">
      <strong>Please correct the highlighted Wholesale Sale information.</strong>
      <ul class="mb-0 mt-1">
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <form method="POST" class="wholesale-sale-form"
        action="{{ $editing ? route('sales.wholesale.update', $sale) : route('sales.wholesale.store') }}"
        id="wholesaleSaleForm">
    @csrf
    @if ($editing)
      @method('PUT')
    @endif

    <div class="card mb-24">
      <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h5 class="card-title mb-0">Invoice Information</h5>
        <span class="badge bg-warning-focus text-warning-600 px-12 py-7 radius-4">Draft</span>
      </div>
      <div class="card-body">
        <div class="row gy-3">
          <div class="col-lg-6">
            <label class="form-label">Customer <span class="text-danger">*</span></label>
            <div class="d-flex align-items-stretch gap-2 wholesale-customer-picker">
              <select name="customer_id" id="wholesaleCustomer"
                      class="form-control form-control-sm js-s2" required>
                <option value="">Select customer</option>
                @foreach ($customers as $customer)
                  <option value="{{ $customer->id }}"
                          @selected((string) old('customer_id', $sale?->customer_id) === (string) $customer->id)>
                    {{ $customer->name }}{{ $customer->phone ? ' — '.$customer->phone : '' }}
                  </option>
                @endforeach
              </select>
              @perm('customer.store', 'add')
                <button type="button" class="btn AjaxModal wholesale-add-customer"
                        data-ajax-modal="{{ route('customer.createModal') }}" data-size="lg"
                        data-onsuccess="WholesaleSaleForm.onCustomerCreated" title="Add customer">
                  <iconify-icon icon="mdi:account-plus-outline"></iconify-icon>
                </button>
              @endperm
            </div>
          </div>

          <div class="col-lg-6">
            <label class="form-label">Issuing Warehouse <span class="text-danger">*</span></label>
            <select name="warehouse_id" id="wholesaleWarehouse"
                    class="form-control form-control-sm js-s2" required>
              <option value="">Select warehouse</option>
              @foreach ($warehouses as $warehouse)
                <option value="{{ $warehouse->id }}" data-branch-id="{{ $warehouse->branch_id }}"
                        @selected((string) $selectedWarehouseId === (string) $warehouse->id)>
                  {{ $warehouse->name }} ({{ $warehouse->code }}) — {{ $warehouse->branch_name }}
                </option>
              @endforeach
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Invoice Date <span class="text-danger">*</span></label>
            <input type="datetime-local" name="sale_at" class="form-control form-control-sm"
                   value="{{ old('sale_at', $sale?->sale_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}"
                   required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Due Date <span class="text-danger">*</span></label>
            <input type="date" name="due_date" class="form-control form-control-sm"
                   value="{{ old('due_date', $sale?->due_date?->format('Y-m-d') ?? now()->addDays(30)->format('Y-m-d')) }}"
                   required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Customer PO / Reference</label>
            <input type="text" name="customer_reference" class="form-control form-control-sm" maxlength="100"
                   value="{{ old('customer_reference', $sale?->customer_reference) }}" placeholder="Optional reference">
          </div>
          <div class="col-md-3">
            <label class="form-label">Invoice Note</label>
            <input type="text" name="note" class="form-control form-control-sm" maxlength="5000"
                   value="{{ old('note', $sale?->note) }}" placeholder="Optional note">
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-24 wholesale-items-card">
      <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
        <div>
          <h5 class="card-title mb-1">Invoice Items</h5>
          <p class="text-muted text-sm mb-0">Search Finished Products and enter negotiated Wholesale rates.</p>
        </div>
        <button type="button" class="btn btn-primary btn-sm app-action-button wholesale-add-product"
                id="addWholesaleLine">
          <iconify-icon icon="mdi:plus"></iconify-icon>
          <span>Add Product</span>
        </button>
      </div>
      <div class="card-body">
        <div class="wholesale-line-header d-none d-md-grid">
          <span>Finished Product</span>
          <span class="wholesale-line-header__available">Available</span>
          <span>Selling Unit</span>
          <span class="text-center">Quantity</span>
          <span class="text-end">Rate / Unit</span>
          <span class="text-end">Line Total</span>
          <span></span>
        </div>
        <div id="wholesaleLines">
          @foreach ($submittedItems as $index => $line)
            @include('backend.modules.wholesale_sale.line', ['index' => $index, 'line' => $line])
          @endforeach
        </div>
        <div class="wholesale-empty-lines text-center text-muted py-32 {{ count($submittedItems) ? 'd-none' : '' }}"
             id="wholesaleEmptyLines">
          <iconify-icon icon="mdi:package-variant-plus" class="text-4xl"></iconify-icon>
          <span>Add at least one Finished Product to the invoice.</span>
        </div>

        <div class="row justify-content-end mt-4">
          <div class="col-lg-5 col-xl-4">
            <div class="wholesale-summary">
              <div><span>Subtotal</span><strong id="wholesaleSubtotal">0.00</strong></div>
              <div class="wholesale-summary-control">
                <label for="wholesaleDiscountValue">Discount</label>
                <div class="d-flex gap-2">
                  <select name="discount_type" id="wholesaleDiscountType" class="form-select form-select-sm">
                    <option value="FLAT" @selected(old('discount_type', $sale?->discount_type ?? 'FLAT') === 'FLAT')>Flat</option>
                    <option value="PERCENT" @selected(old('discount_type', $sale?->discount_type ?? 'FLAT') === 'PERCENT')>%</option>
                  </select>
                  <input type="number" name="discount_value" id="wholesaleDiscountValue"
                         class="form-control form-control-sm text-end" min="0" step="0.01"
                         value="{{ old('discount_value', number_format((float) ($sale?->discount_value ?? 0), 2, '.', '')) }}">
                </div>
              </div>
              <div class="wholesale-summary-control">
                <label for="wholesaleTax">Tax</label>
                <input type="number" name="tax" id="wholesaleTax" class="form-control form-control-sm text-end"
                       min="0" step="0.01"
                       value="{{ old('tax', number_format((float) ($sale?->tax ?? 0), 2, '.', '')) }}">
              </div>
              <div class="wholesale-summary-total"><span>Invoice Total</span><strong id="wholesaleTotal">0.00</strong></div>
            </div>
          </div>
        </div>
      </div>
      <div class="card-footer d-flex align-items-center justify-content-end gap-2 flex-wrap">
        <a href="{{ route('sales.wholesale.index') }}" class="btn btn-outline-danger px-18 py-9 radius-8">Cancel</a>
        <button type="submit"
                class="btn app-gradient-action wholesale-save-draft px-20 py-9 radius-8 app-action-button">
          <iconify-icon icon="mdi:content-save-outline"></iconify-icon>
          <span>Save Draft</span>
        </button>
        <button type="button" class="btn btn-success px-20 py-9 radius-8 app-action-button" id="openWholesaleReview">
          <iconify-icon icon="mdi:clipboard-check-outline"></iconify-icon>
          <span>Payment & Review</span>
        </button>
      </div>
    </div>

    <div class="modal fade" id="wholesaleReviewModal" tabindex="-1"
         aria-labelledby="wholesaleReviewModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content pos-checkout-modal">
          <div class="modal-header border-0 px-24 pt-20 pb-12">
            <div>
              <h5 class="modal-title mb-1" id="wholesaleReviewModalLabel">Payment & Review</h5>
              <p class="text-muted text-sm mb-0">
                Prepare split payment first; any remaining balance becomes Customer Receivable.
              </p>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body px-24 py-12">
            <div class="pos-checkout-summary">
              <div>
                <span>Invoice Total</span>
                <strong id="wholesaleReviewTotal">0.00</strong>
              </div>
              <div>
                <span>Applied Payment</span>
                <strong id="wholesaleReviewPaid">0.00</strong>
              </div>
              <div>
                <span>Remaining Due</span>
                <strong id="wholesaleReviewDue">0.00</strong>
              </div>
              <div class="pos-checkout-change-card" id="wholesaleChangeCard">
                <span>Change Due</span>
                <strong id="wholesaleReviewChange">0.00</strong>
              </div>
            </div>

            <div class="alert alert-light border pos-checkout-customer"
                 id="wholesaleReviewCustomerMessage"></div>

            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
              <div>
                <h6 class="mb-1">Payment Methods</h6>
                <p class="text-muted text-sm mb-0">
                  Add more than one row for split payment, or leave empty for full due.
                </p>
              </div>
              <button type="button" class="btn btn-outline-primary btn-sm app-action-button" id="addWholesalePayment">
                <iconify-icon icon="mdi:plus"></iconify-icon>
                <span>Add Payment</span>
              </button>
            </div>

            <div class="pos-payment-list" id="wholesalePaymentRows"></div>
            <div class="alert alert-danger d-none mt-3 mb-0" id="wholesaleReviewWarning"></div>
          </div>

          <div class="modal-footer border-0 px-24 py-16">
            <button type="button" class="btn btn-outline-secondary px-18 py-9 radius-8"
                    data-bs-dismiss="modal">
              Back to Invoice
            </button>
            <button type="submit" name="review" value="1"
                    class="btn btn-success px-22 py-9 radius-8 app-action-button"
                    id="continueWholesaleReview">
              <iconify-icon icon="mdi:clipboard-check-outline"></iconify-icon>
              <span>Continue to Review</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </form>

  <template id="wholesaleLineTemplate">
    @include('backend.modules.wholesale_sale.line', ['index' => '__INDEX__', 'line' => []])
  </template>
  <template id="wholesalePaymentTemplate">
    <div class="pos-payment-row wholesale-payment-row" data-payment-index="__PAYMENT_INDEX__">
      <div>
        <label class="form-label">Payment Method <span class="text-danger">*</span></label>
        <select name="payments[__PAYMENT_INDEX__][payment_type_id]"
                class="form-control js-wholesale-payment-type" required>
          <option value="">Select method</option>
          @foreach ($paymentTypes as $paymentType)
            <option value="{{ $paymentType->id }}" data-allows-change="{{ $paymentType->allows_change ? 1 : 0 }}">
              {{ $paymentType->name }}
            </option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="form-label">Receiving Account <span class="text-danger">*</span></label>
        <select name="payments[__PAYMENT_INDEX__][account_id]"
                class="form-control js-wholesale-payment-account" required>
          <option value="">Select account</option>
        </select>
      </div>
      <div>
        <label class="form-label">Received / Tendered <span class="text-danger">*</span></label>
        <input type="number" name="payments[__PAYMENT_INDEX__][amount]"
               class="form-control text-end js-wholesale-payment-amount" min="0.01" step="0.01"
               placeholder="0.00" required>
      </div>
      <div>
        <label class="form-label">Reference</label>
        <input type="text" name="payments[__PAYMENT_INDEX__][reference_no]"
               class="form-control" maxlength="100" placeholder="Optional">
      </div>
      <button type="button" class="btn pos-payment-remove js-remove-wholesale-payment"
              title="Remove payment row" aria-label="Remove payment row">
        <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
      </button>
    </div>
  </template>
@endsection

@section('script')
  <script>
    (function($) {
      const productUrl = @json(route('sales.wholesale.products'));
      const unitUrlTemplate = @json(route('sales.wholesale.units', ['finishedProduct' => 'PRODUCT_ID']));
      const cashAccounts = @json($cashAccounts);
      let nextLineIndex = {{ count($submittedItems) }};
      let nextPaymentIndex = 0;

      window.WholesaleSaleForm = {
        onCustomerCreated: function(response) {
          if (!response?.id) return;
          const label = [response.name, response.phone].filter(Boolean).join(' — ');
          $('#wholesaleCustomer').append(new Option(label, response.id, true, true)).trigger('change.select2');
        },
      };

      function productSelect($select) {
        $select.select2({
          width: '100%',
          placeholder: 'Search product, code or barcode',
          allowClear: true,
          ajax: {
            url: productUrl,
            dataType: 'json',
            delay: 250,
            data: params => ({
              q: params.term || '',
              page: params.page || 1,
              warehouse_id: $('#wholesaleWarehouse').val(),
            }),
            processResults: response => response,
          },
        });
      }

      function unitUrl(productId) {
        return unitUrlTemplate.replace('PRODUCT_ID', productId);
      }

      function loadUnits($line, productId, selectedUnitId = '', preservePrice = false) {
        const $unit = $line.find('.js-wholesale-unit').prop('disabled', true);
        $.getJSON(unitUrl(productId)).done(function(response) {
          $unit.empty();
          (response.results || []).forEach(function(unit) {
            const option = new Option(unit.text, unit.id, false, String(unit.id) === String(selectedUnitId));
            $(option)
              .attr('data-default-price', unit.default_price ?? '')
              .attr('data-precision', unit.precision ?? 6);
            $unit.append(option);
          });
          if (!$unit.val() && response.results?.length) $unit.val(String(response.results[0].id));
          $unit.prop('disabled', false);
          S2.auto($line);
          $unit.attr('data-selected-precision', $unit.find('option:selected').data('precision') ?? 6);
          if (!preservePrice) {
            $line.find('.js-wholesale-price').val($unit.find('option:selected').data('default-price') || '');
          }
          recalculate();
        }).fail(function() {
          $unit.prop('disabled', false);
        });
      }

      function addLine() {
        if (!$('#wholesaleWarehouse').val()) {
          window.Swal
            ? Swal.fire({ icon: 'warning', title: 'Select an issuing warehouse first' })
            : alert('Select an issuing warehouse first.');
          return;
        }
        const html = $('#wholesaleLineTemplate').html().replaceAll('__INDEX__', nextLineIndex++);
        const $line = $(html);
        $('#wholesaleLines').append($line);
        productSelect($line.find('.js-wholesale-product'));
        refreshLines();
      }

      function refreshLines() {
        $('#wholesaleEmptyLines').toggleClass('d-none', $('#wholesaleLines .wholesale-line').length > 0);
        recalculate();
      }

      function recalculate() {
        let subtotal = 0;
        $('#wholesaleLines .wholesale-line').each(function() {
          const $line = $(this);
          const total = Math.max(Number($line.find('.js-wholesale-quantity').val() || 0), 0)
            * Math.max(Number($line.find('.js-wholesale-price').val() || 0), 0);
          $line.find('.js-wholesale-line-total').text(total.toFixed(2));
          subtotal += total;
        });
        const discountValue = Math.max(Number($('#wholesaleDiscountValue').val() || 0), 0);
        const discount = $('#wholesaleDiscountType').val() === 'PERCENT'
          ? subtotal * Math.min(discountValue, 100) / 100
          : Math.min(discountValue, subtotal);
        const tax = Math.max(Number($('#wholesaleTax').val() || 0), 0);
        const total = Math.max(subtotal - discount + tax, 0);
        $('#wholesaleSubtotal').text(subtotal.toFixed(2));
        $('#wholesaleTotal, #wholesaleReviewTotal').text(total.toFixed(2));
        recalculatePayments();
      }

      function selectedBranchId() {
        return Number($('#wholesaleWarehouse option:selected').data('branch-id') || 0);
      }

      function populatePaymentAccounts($select, selectedAccountId = '') {
        const branchId = selectedBranchId();
        $select.empty().append(new Option('Select account', ''));

        cashAccounts
          .filter(account => Number(account.branch_id) === branchId)
          .forEach(function(account) {
            const text = `${account.code} — ${account.name}`;
            $select.append(new Option(
              text,
              account.id,
              false,
              String(account.id) === String(selectedAccountId),
            ));
          });
      }

      function addPaymentRow(defaultAmount = '') {
        const html = $('#wholesalePaymentTemplate').html().replaceAll('__PAYMENT_INDEX__', nextPaymentIndex++);
        const $row = $(html);
        populatePaymentAccounts($row.find('.js-wholesale-payment-account'));
        $row.find('.js-wholesale-payment-amount').val(defaultAmount);
        $('#wholesalePaymentRows').append($row);
        S2.auto($row);
        recalculatePayments();
      }

      function wholesalePaymentSummary(invoiceTotal) {
        let remaining = invoiceTotal;
        let applied = 0;
        let change = 0;
        let error = '';

        $('#wholesalePaymentRows .wholesale-payment-row').each(function() {
          const $row = $(this);
          const tendered = Math.max(Number($row.find('.js-wholesale-payment-amount').val() || 0), 0);
          const $method = $row.find('.js-wholesale-payment-type option:selected');
          const allowsChange = Number($method.data('allows-change') || 0) === 1;

          if (tendered <= 0 || error) {
            return;
          }
          if (remaining <= 0.00001) {
            error = 'Remove unused payment rows after the invoice total has been fully allocated.';
            return;
          }

          if (tendered > remaining) {
            if (!allowsChange) {
              const methodName = $method.val() ? $method.text().trim() : 'This payment method';
              error = `${methodName} cannot exceed the remaining amount. Select Cash to calculate change.`;
              return;
            }

            applied += remaining;
            change += tendered - remaining;
            remaining = 0;
            return;
          }

          applied += tendered;
          remaining -= tendered;
        });

        return {
          applied,
          change,
          due: Math.max(remaining, 0),
          error,
        };
      }

      function recalculatePayments() {
        const total = Math.max(Number($('#wholesaleTotal').text() || 0), 0);
        const payment = wholesalePaymentSummary(total);

        $('#wholesaleReviewTotal').text(total.toFixed(2));
        $('#wholesaleReviewPaid').text(payment.applied.toFixed(2));
        $('#wholesaleReviewDue').text(payment.due.toFixed(2));
        $('#wholesaleReviewChange').text(payment.change.toFixed(2));
        $('#wholesaleChangeCard').toggleClass('has-change', payment.change > 0.00001);
        $('#wholesaleReviewWarning')
          .text(payment.error)
          .toggleClass('d-none', payment.error === '');
        $('#continueWholesaleReview').prop('disabled', payment.error !== '' || total <= 0);
      }

      function updateWholesaleCustomerContext() {
        const customer = $('#wholesaleCustomer option:selected').text().trim();
        const message = customer
          ? `${customer} · partial payment or full due is allowed`
          : 'Select a Customer before continuing to review.';

        $('#wholesaleReviewCustomerMessage').text(message);
        recalculatePayments();
      }

      $(document).on('select2:select', '.js-wholesale-product', function(event) {
        const $line = $(this).closest('.wholesale-line');
        const product = event.params?.data;
        if (!product?.id) return;
        const duplicate = $('#wholesaleLines .js-wholesale-product').not(this).filter(function() {
          return String($(this).val()) === String(product.id);
        }).length > 0;
        if (duplicate) {
          $(this).val(null).trigger('change.select2');
          window.Swal && Swal.fire({ icon: 'warning', title: 'Product already added' });
          return;
        }
        $line.find('.js-wholesale-stock').text(product.stock_quantity ?? '0');
        $line.find('.js-wholesale-stock-unit').text(product.stock_unit_code ?? '');
        loadUnits($line, product.id);
      });

      $(document).on('select2:clear', '.js-wholesale-product', function() {
        const $line = $(this).closest('.wholesale-line');
        $line.find('.js-wholesale-stock').text('—');
        $line.find('.js-wholesale-stock-unit').text('');
        $line.find('.js-wholesale-unit').empty().prop('disabled', true);
        $line.find('.js-wholesale-price').val('');
        recalculate();
      });

      $(document).on('change', '.js-wholesale-unit', function() {
        const price = $(this).find('option:selected').data('default-price');
        const precision = $(this).find('option:selected').data('precision') ?? 6;
        $(this).attr('data-selected-precision', precision);
        if (price !== undefined && price !== '') {
          $(this).closest('.wholesale-line').find('.js-wholesale-price').val(price);
        }
        recalculate();
      });
      $(document).on('click', '.js-wholesale-quantity-step', function() {
        const $line = $(this).closest('.wholesale-line');
        const $quantity = $line.find('.js-wholesale-quantity');
        const precision = Math.max(
          0,
          Math.min(6, Number($line.find('.js-wholesale-unit').attr('data-selected-precision') || 0)),
        );
        const step = precision === 0 ? 1 : Number(`0.${'0'.repeat(precision - 1)}1`);
        const direction = Number($(this).data('direction'));
        const nextValue = Math.max(step, Number($quantity.val() || 0) + direction * step);
        $quantity.val(nextValue.toFixed(precision)).trigger('input');
      });
      $(document).on('input', '.js-wholesale-quantity, .js-wholesale-price', recalculate);
      $(document).on('input change', '.js-wholesale-payment-amount, .js-wholesale-payment-type', recalculatePayments);
      $('#wholesaleDiscountValue, #wholesaleTax').on('input', recalculate);
      $('#wholesaleDiscountType').on('change', recalculate);
      $('#wholesaleCustomer').on('change', updateWholesaleCustomerContext);
      $('#addWholesaleLine').on('click', addLine);
      $(document).on('click', '.js-remove-wholesale-line', function() {
        $(this).closest('.wholesale-line').remove();
        refreshLines();
      });
      $('#addWholesalePayment').on('click', function() {
        addPaymentRow('');
      });
      $(document).on('click', '.js-remove-wholesale-payment', function() {
        $(this).closest('.wholesale-payment-row').remove();
        recalculatePayments();
      });
      $('#openWholesaleReview').on('click', function() {
        if (!$('#wholesaleLines .wholesale-line').length) {
          window.Swal
            ? Swal.fire({ icon: 'warning', title: 'Add at least one Finished Product' })
            : alert('Add at least one Finished Product.');
          return;
        }

        recalculate();
        if (!$('#wholesalePaymentRows .wholesale-payment-row').length) {
          addPaymentRow(Number($('#wholesaleTotal').text() || 0).toFixed(2));
        }
        updateWholesaleCustomerContext();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('wholesaleReviewModal')).show();
      });
      $('#wholesaleReviewModal')
        .on('show.bs.modal', function() {
          $('#wholesalePaymentRows').find('select, input, button').prop('disabled', false);
        })
        .on('hidden.bs.modal', function() {
          $('#wholesalePaymentRows').find('select, input, button').prop('disabled', true);
        });
      $('#wholesaleWarehouse').on('change', function() {
        if ($('#wholesaleLines .wholesale-line').length) {
          $('#wholesaleLines').empty();
          $('#wholesalePaymentRows').empty();
        }

        if ($(this).val()) {
          addLine();
          return;
        }

        refreshLines();
      });
      $('#wholesaleSaleForm').on('submit', function(event) {
        if (!$('#wholesaleLines .wholesale-line').length) {
          event.preventDefault();
          window.Swal
            ? Swal.fire({ icon: 'warning', title: 'Add at least one Finished Product' })
            : alert('Add at least one Finished Product.');
        }
      });

      $('#wholesaleLines .wholesale-line').each(function() {
        const $line = $(this);
        productSelect($line.find('.js-wholesale-product'));
        loadUnits($line, $line.find('.js-wholesale-product').val(), $line.find('.js-wholesale-unit').val(), true);
      });
      S2.auto($('#wholesaleSaleForm'));

      if (!$('#wholesaleLines .wholesale-line').length && $('#wholesaleWarehouse').val()) {
        addLine();
      }

      refreshLines();
      updateWholesaleCustomerContext();
    })(jQuery);
  </script>
@endsection
