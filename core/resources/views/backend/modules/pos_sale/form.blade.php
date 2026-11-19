@extends('backend.layouts.master')

@php
  $editing = (bool) $sale;
  $submittedItems = old('items', $items);
  $submittedItems = is_array($submittedItems) ? $submittedItems : [];
  $selectedWarehouseId = old('warehouse_id', $sale?->warehouse_id ?? current_warehouse_id());
  $operatorName = auth()->user()?->name ?? 'User';
@endphp

@section('meta')
  <title>{{ $editing ? 'Edit' : 'New' }} POS Sale</title>
@endsection

@section('body_class', 'purchase-order-screen pos-sale-screen')

@section('content')
  <div class="purchase-order-screen-shell pos-sale-screen-shell">
    @if ($errors->any())
      <div class="alert alert-danger">
        <strong>Please correct the highlighted POS information.</strong>
        <ul class="mb-0 mt-1">
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <form method="POST" action="{{ $editing ? route('sales.pos.update', $sale) : route('sales.pos.store') }}" id="posSaleForm">
      @csrf
      @if ($editing)
        @method('PUT')
      @endif

      <div class="purchase-order-workspace pos-sale-workspace">
        <section class="pos-gallery-panel">
          <div class="pos-gallery-toolbar">
            <div class="pos-gallery-welcome">
              <span>Welcome,</span>
              <strong>{{ $operatorName }}</strong>
              <small>{{ now()->format('l, d M Y') }}</small>
            </div>

            <div class="po-search-box pos-product-search">
              <iconify-icon icon="mdi:magnify"></iconify-icon>
              <input type="search" id="posCatalogSearch" class="form-control"
                     placeholder="Search product, code or barcode" autocomplete="off">
            </div>
          </div>

          <div class="pos-category-strip" role="group" aria-label="Product categories">
            <input type="hidden" id="posCatalogCategory" value="">
            <button type="button" class="pos-category-pill active" data-category-id="">All Categories</button>
            @foreach ($categories as $category)
              <button type="button" class="pos-category-pill" data-category-id="{{ $category->id }}">
                {{ $category->name }}
              </button>
            @endforeach
            <span class="pos-catalog-count" id="posCatalogResultCount">0 products</span>
          </div>

          <div class="pos-gallery-scroll">
              <div class="po-supplier-gate" id="posWarehouseGate">
                <span class="po-supplier-gate__icon">
                  <iconify-icon icon="mdi:warehouse"></iconify-icon>
                </span>
                <strong>Select an issuing warehouse</strong>
                <span>Available Finished Product stock and retail prices will appear here.</span>
              </div>

              <div class="po-catalog-grid pos-catalog-grid d-none" id="posProductCatalog" aria-live="polite"></div>
              <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-3 d-none" id="posCatalogLoadMore">
                <iconify-icon icon="mdi:chevron-down"></iconify-icon>
                <span>Load more products</span>
              </button>
          </div>
        </section>

        <section class="card po-order-panel pos-order-panel">
          <div class="card-header po-panel-header">
            <div>
              <div class="d-flex align-items-center gap-2">
                <h5 class="card-title mb-0">{{ $editing ? 'Edit Order' : 'Order List' }}</h5>
                <x-backend.page-note class="po-header-note" title="How this step works" label="View POS Draft rules" align="start">
                  Select an issuing warehouse, add available Finished Products and review the server-calculated totals.
                  Saving this Draft does not reduce stock, receive payment or create a Finance journal.
                </x-backend.page-note>
              </div>
            </div>
            <span class="badge bg-warning-focus text-warning-600 px-12 py-7 radius-8">Draft</span>
          </div>

          <div class="card-body po-order-body">
            <div class="pos-customer-block">
              <div class="po-order-meta-field">
                <label class="form-label">Customer Information <span class="text-muted text-xs">(optional)</span></label>
                <div class="po-supplier-picker">
                  <select name="customer_id" id="posCustomer"
                          class="form-control form-control-sm js-s2"
                          data-dropdown-css-class="po-select2-dropdown"
                          placeholder="Walk-in customer">
                    <option value="">Walk-in Customer</option>
                    @foreach ($customers as $customer)
                      <option value="{{ $customer->id }}" @selected((string) old('customer_id', $sale?->customer_id) === (string) $customer->id)>
                        {{ $customer->name }}{{ $customer->phone ? ' — '.$customer->phone : '' }}{{ $customer->code ? ' ('.$customer->code.')' : '' }}
                      </option>
                    @endforeach
                  </select>
                  @perm('customer.store', 'add')
                    <button type="button" class="btn po-supplier-add AjaxModal"
                            data-ajax-modal="{{ route('customer.createModal') }}"
                            data-size="lg"
                            data-onsuccess="PosSaleForm.onCustomerCreated"
                            title="Add customer" aria-label="Add customer">
                      <iconify-icon icon="mdi:account-plus-outline"></iconify-icon>
                    </button>
                  @endperm
                </div>
                <div class="pos-customer-context" id="posCustomerContext">
                  <iconify-icon icon="mdi:account-outline"></iconify-icon>
                  <span>Walk-in Customer · full payment required at checkout</span>
                </div>
              </div>
            </div>

            <div class="pos-barcode-entry">
              <label class="form-label" for="posBarcodeInput">Scan Product Barcode</label>
              <div class="po-search-box pos-product-search pos-barcode-scan">
                <iconify-icon icon="mdi:barcode-scan"></iconify-icon>
                <input type="text" id="posBarcodeInput" class="form-control"
                       placeholder="Scan barcode to add product" aria-label="Scan product barcode"
                       autocomplete="off" autocapitalize="off" spellcheck="false" autofocus>
              </div>
              <span class="pos-customer-context text-muted">Click to focus• Scan to auto-add• type manually and press Enter.</span>
            </div>

            <div class="pos-order-settings-grid po-order-meta-grid">
              <div class="po-order-meta-field">
                <label class="form-label">Issuing Warehouse <span class="text-danger">*</span></label>
                <select name="warehouse_id" id="posWarehouse"
                        class="form-control form-control-sm js-s2"
                        data-dropdown-css-class="po-select2-dropdown"
                        placeholder="Select warehouse" required>
                  <option value=""></option>
                  @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" data-branch-id="{{ $warehouse->branch_id }}"
                            @selected((string) $selectedWarehouseId === (string) $warehouse->id)>
                      {{ $warehouse->name }} ({{ $warehouse->code }}) - {{ $warehouse->branch_name }}
                    </option>
                  @endforeach
                </select>
              </div>

              <div class="po-order-meta-field">
                <label class="form-label">Sale Date & Time <span class="text-danger">*</span></label>
                <input type="datetime-local" name="sale_at" class="form-control form-control-sm"
                       value="{{ old('sale_at', $sale?->sale_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}"
                       required>
              </div>

              <div class="pos-order-note-field">
                <label class="form-label">Sale Note</label>
                <input type="text" name="note" class="form-control form-control-sm" maxlength="5000"
                       value="{{ old('note', $sale?->note) }}" placeholder="Optional counter note">
              </div>
            </div>

            <div class="po-cart-section">
              <div class="po-cart-heading">
                <div>
                  <h6 class="mb-1 po-selected-items-title">
                    Order Details
                    <span class="po-count-badge po-selected-count" id="posSelectedItemCount">0</span>
                  </h6>
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm px-10 radius-8" id="clearPosItems">
                  <iconify-icon icon="mdi:delete-sweep-outline"></iconify-icon>
                  <span>Clear All</span>
                </button>
              </div>

              <div id="posCartTableHeader"
                   class="pos-cart-table-header {{ count($submittedItems) ? '' : 'd-none' }}"
                   aria-hidden="true">
                <span>Product</span>
                <span>Stock</span>
                <span>Unit</span>
                <span>Quantity</span>
                <span>Price</span>
                <span>Total</span>
                <span></span>
              </div>

              <div class="po-empty-cart" id="emptyPosCart">
                <span><iconify-icon icon="mdi:cart-outline"></iconify-icon></span>
                <strong>Cart is empty</strong>
                <small>Choose an in-stock Finished Product from the gallery.</small>
              </div>

              <div class="po-cart-lines" id="posSaleItems">
                @foreach ($submittedItems as $index => $line)
                  @include('backend.modules.pos_sale.line', ['index' => $index, 'line' => $line])
                @endforeach
              </div>
            </div>

            <div class="po-order-summary">
              <div class="po-summary-row">
                <span>Subtotal</span>
                <strong id="posSubtotalPreview">0.00</strong>
              </div>
              <div class="po-summary-input">
                <label for="posDiscountValue">Discount</label>
                <div class="po-discount-control">
                  <select name="discount_type" id="posDiscountType" class="form-select" aria-label="Discount type">
                    <option value="FLAT" @selected(old('discount_type', $sale?->discount_type ?? 'FLAT') === 'FLAT')>Flat</option>
                    <option value="PERCENT" @selected(old('discount_type', $sale?->discount_type ?? 'FLAT') === 'PERCENT')>%</option>
                  </select>
                  <input type="number" name="discount_value" id="posDiscountValue"
                         class="form-control text-end" min="0" step="0.01"
                         value="{{ old('discount_value', number_format((float) ($sale?->discount_value ?? 0), 2, '.', '')) }}">
                </div>
              </div>
              <div class="po-summary-input">
                <label for="posTax">Tax</label>
                <input type="number" name="tax" id="posTax" class="form-control text-end"
                       min="0" step="0.01"
                       value="{{ old('tax', number_format((float) ($sale?->tax ?? 0), 2, '.', '')) }}">
              </div>
              <div class="po-summary-row po-summary-total">
                <span>Sale Total</span>
                <strong id="posTotalPreview">0.00</strong>
              </div>
            </div>

            <button type="button" class="pos-cart-submit js-open-pos-checkout" title="Prepare payment and review Sale">
              <iconify-icon icon="mdi:cash-register"></iconify-icon>
              <span>Payment / Review</span>
            </button>
          </div>

        </section>
      </div>

      <nav class="pos-action-dock" aria-label="POS actions">
        <button type="submit" class="pos-dock-action pos-dock-action--hold"
                formnovalidate title="Save Draft and hold this order">
          <iconify-icon icon="mdi:pause-circle-outline"></iconify-icon>
          <span>Hold</span>
        </button>
        <a href="{{ route('sales.pos.index', ['status' => 'DRAFT']) }}" class="pos-dock-action pos-dock-action--held">
          <iconify-icon icon="mdi:format-list-bulleted-square"></iconify-icon>
          <span>Held Orders</span>
        </a>
        <button type="button" class="pos-dock-action pos-dock-action--payment js-open-pos-checkout">
          <iconify-icon icon="mdi:cash-multiple"></iconify-icon>
          <span>Payment</span>
        </button>
        <a href="{{ route('sales.pos.index') }}" class="pos-dock-action pos-dock-action--orders">
          <iconify-icon icon="mdi:cart-check"></iconify-icon>
          <span>View Orders</span>
        </a>
        <a href="{{ route('sales.pos.create') }}" class="pos-dock-action pos-dock-action--new">
          <iconify-icon icon="mdi:refresh"></iconify-icon>
          <span>New Order</span>
        </a>
        <button type="button" class="pos-dock-action pos-dock-action--transactions" disabled title="Available with the Payment workflow">
          <iconify-icon icon="mdi:swap-horizontal"></iconify-icon>
          <span>Transactions</span>
        </button>
      </nav>

      <div class="modal fade" id="posCheckoutModal" tabindex="-1" aria-labelledby="posCheckoutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
          <div class="modal-content pos-checkout-modal">
            <div class="modal-header border-0 px-24 pt-20 pb-12">
              <div>
                <h5 class="modal-title mb-1" id="posCheckoutModalLabel">Payment & Review</h5>
                <p class="text-muted text-sm mb-0">Prepare split payment first; stock and Finance post after final confirmation.</p>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body px-24 py-12">
              <div class="pos-checkout-summary">
                <div>
                  <span>Order Total</span>
                  <strong id="checkoutOrderTotal">0.00</strong>
                </div>
                <div>
                  <span>Applied Payment</span>
                  <strong id="checkoutPaidTotal">0.00</strong>
                </div>
                <div>
                  <span>Remaining Due</span>
                  <strong id="checkoutDueTotal">0.00</strong>
                </div>
                <div class="pos-checkout-change-card" id="checkoutChangeCard">
                  <span>Change Due</span>
                  <strong id="checkoutChangeTotal">0.00</strong>
                </div>
              </div>

              <div class="alert alert-light border pos-checkout-customer" id="checkoutCustomerMessage"></div>

              <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                <div>
                  <h6 class="mb-1">Payment Methods</h6>
                  <p class="text-muted text-sm mb-0">Add more than one row for split payment.</p>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm app-action-button" id="addPosPaymentRow">
                  <iconify-icon icon="mdi:plus"></iconify-icon>
                  <span>Add Payment</span>
                </button>
              </div>

              <div class="pos-payment-list" id="posPaymentRows"></div>
              <div class="alert alert-danger d-none mt-3 mb-0" id="posCheckoutWarning"></div>
            </div>

            <div class="modal-footer border-0 px-24 py-16">
              <button type="button" class="btn btn-outline-secondary px-18 py-9 radius-8" data-bs-dismiss="modal">
                Back to Order
              </button>
              <button type="submit" name="review" value="1"
                      class="btn btn-success px-22 py-9 radius-8 app-action-button" id="completePosCheckout">
                <iconify-icon icon="mdi:clipboard-check-outline"></iconify-icon>
                <span>Continue to Review</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>

  <template id="posSaleLineTemplate">
    @include('backend.modules.pos_sale.line', ['index' => '__INDEX__', 'line' => []])
  </template>

  <template id="posPaymentRowTemplate">
    <div class="pos-payment-row" data-payment-index="__PAYMENT_INDEX__">
      <div>
        <label class="form-label">Payment Method <span class="text-danger">*</span></label>
        <select name="payments[__PAYMENT_INDEX__][payment_type_id]" class="form-control js-pos-payment-type" required>
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
        <select name="payments[__PAYMENT_INDEX__][account_id]" class="form-control js-pos-payment-account" required>
          <option value="">Select account</option>
        </select>
      </div>
      <div>
        <label class="form-label">Received / Tendered <span class="text-danger">*</span></label>
        <input type="number" name="payments[__PAYMENT_INDEX__][amount]"
               class="form-control text-end js-pos-payment-amount" min="0.01" step="0.01"
               placeholder="0.00" required>
      </div>
      <div>
        <label class="form-label">Reference</label>
        <input type="text" name="payments[__PAYMENT_INDEX__][reference_no]"
               class="form-control" maxlength="100" placeholder="Optional">
      </div>
      <button type="button" class="btn pos-payment-remove" title="Remove payment row" aria-label="Remove payment row">
        <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
      </button>
    </div>
  </template>
@endsection

@section('script')
  <script>
    (function($) {
      const catalogUrl = @json(route('sales.pos.catalog'));
      const unitUrlTemplate = @json(route('sales.pos.units', ['finishedProduct' => 'PRODUCT_ID']));
      const fallbackProductImage = @json(asset('theme/admin/assets/images/datatable/purchase1.svg'));
      const cashAccounts = @json($cashAccounts);
      let nextIndex = {{ count($submittedItems) }};
      let nextPaymentIndex = 0;
      let catalogPage = 1;
      let catalogHasMore = false;
      let catalogLoading = false;
      let catalogSearchTimer;
      let previousWarehouse = String($('#posWarehouse').val() || '');
      let revertingWarehouse = false;

      window.PosSaleForm = {
        onCustomerCreated: function(response) {
          if (!response?.id) {
            return;
          }

          const $customer = $('#posCustomer');
          const customerLabel = [response.name, response.phone]
            .filter(Boolean)
            .join(' — ');
          $customer.find(`option[value="${response.id}"]`).remove();
          $customer.append(new Option(customerLabel, response.id, true, true)).trigger('change.select2');
        },
      };

      function escapeHtml(value) {
        return $('<div>').text(value ?? '').html();
      }

      function selectedProductIds() {
        return new Set(
          $('#posSaleItems .pos-cart-line').map(function() {
            return String($(this).data('product-id'));
          }).get(),
        );
      }

      function updateCatalogSelection() {
        const selected = selectedProductIds();
        $('#posProductCatalog .pos-catalog-card').each(function() {
          $(this).toggleClass('is-selected', selected.has(String($(this).data('product-id'))));
        });
      }

      function catalogMessage(icon, title, message) {
        return `
          <div class="po-catalog-message">
            <span><iconify-icon icon="${icon}"></iconify-icon></span>
            <strong>${escapeHtml(title)}</strong>
            <small>${escapeHtml(message)}</small>
          </div>`;
      }

      function renderCatalog(products, append) {
        const $catalog = $('#posProductCatalog');
        if (!append) {
          $catalog.empty();
        }

        if (!products.length && !append) {
          $catalog.html(catalogMessage('mdi:package-variant-remove', 'No Finished Products found', 'Try another search, category or warehouse.'));
          return;
        }

        products.forEach(function(product) {
          const $card = $('<button>', {
            type: 'button',
            class: 'po-catalog-card pos-catalog-card',
            'data-product-id': product.id,
            disabled: !product.in_stock,
          }).data('product', product);

          const $productImage = $('<img>', {
              src: product.image_url,
              alt: product.name,
              loading: 'lazy',
            }).on('error', function() {
              if (this.src !== fallbackProductImage) {
                this.src = fallbackProductImage;
              }
            });
          const $image = $('<div>', { class: 'po-catalog-card__image' }).append($productImage);
          const priceText = product.retail_price === null ? 'Set price in cart' : `৳${Number(product.retail_price).toFixed(2)}`;
          const stockClass = product.in_stock ? 'pos-stock-badge--available' : 'pos-stock-badge--empty';
          const $content = $('<div>', { class: 'po-catalog-card__content' })
            .append($('<div>', { class: 'pos-catalog-card__badges' })
              .append($('<span>', { class: `pos-stock-badge ${stockClass}` })
                .text(`Stock: ${product.stock_quantity} ${product.base_unit_code}`)))
            .append($('<strong>', { title: `${product.name} (${product.code})` }).text(product.name))
            .append($('<small>').text(product.code))
            .append($('<b>', { class: 'pos-product-price' }).text(priceText))
            .append($('<span>', { class: 'po-card-add', title: product.in_stock ? 'Add to cart' : 'Out of stock' })
              .append(`<iconify-icon icon="${product.in_stock ? 'mdi:plus' : 'mdi:package-variant-remove'}"></iconify-icon>`));

          $card.append($image, $content);
          $catalog.append($card);
        });

        updateCatalogSelection();
      }

      function loadCatalog(append = false) {
        const warehouseId = $('#posWarehouse').val();
        if (!warehouseId) {
          $('#posWarehouseGate').removeClass('d-none');
          $('#posProductCatalog, #posCatalogLoadMore').addClass('d-none');
          $('#posCatalogResultCount').text('0 products');
          return;
        }
        if (catalogLoading) {
          return;
        }

        catalogLoading = true;
        if (!append) {
          catalogPage = 1;
          $('#posProductCatalog')
            .removeClass('d-none')
            .html('<div class="po-catalog-loading"><span></span><span></span><span></span><span></span></div>');
        }
        $('#posWarehouseGate').addClass('d-none');

        $.getJSON(catalogUrl, {
          warehouse_id: warehouseId,
          q: $('#posCatalogSearch').val(),
          category_id: $('#posCatalogCategory').val(),
          page: catalogPage,
        }).done(function(response) {
          const products = response.results || [];
          renderCatalog(products, append);
          catalogHasMore = Boolean(response.pagination?.more);
          $('#posCatalogLoadMore').toggleClass('d-none', !catalogHasMore);
          const totalCount = Number(response.pagination?.total || 0);
          $('#posCatalogResultCount').text(`${totalCount} ${totalCount === 1 ? 'product' : 'products'}`);
        }).fail(function(xhr) {
          const message = xhr.responseJSON?.message || 'Unable to load Finished Products.';
          $('#posProductCatalog').html(catalogMessage('mdi:alert-circle-outline', 'Product gallery unavailable', message));
          $('#posCatalogLoadMore').addClass('d-none');
        }).always(function() {
          catalogLoading = false;
        });
      }

      function unitUrl(productId) {
        return unitUrlTemplate.replace('PRODUCT_ID', productId);
      }

      function loadUnits($line, productId, selectedUnitId, existingPrice, preservePrice) {
        const $unit = $line.find('.js-pos-unit');
        $unit.prop('disabled', true);

        $.getJSON(unitUrl(productId)).done(function(response) {
          $unit.empty();
          (response.results || []).forEach(function(unit) {
            const option = new Option(unit.text, unit.id, false, String(unit.id) === String(selectedUnitId));
            $(option)
              .attr('data-precision', unit.precision)
              .attr('data-default-price', unit.default_price ?? '');
            $unit.append(option);
          });

          if (!$unit.val() && response.results?.length) {
            $unit.val(String(response.results[0].id));
          }
          $unit.prop('disabled', false);
          S2.auto($line);

          const selectedOption = $unit.find('option:selected');
          const precision = Number(selectedOption.data('precision') ?? 6);
          $unit.attr('data-selected-precision', precision);
          if (!preservePrice || !existingPrice) {
            $line.find('.js-pos-price').val(selectedOption.data('default-price') || '');
          }
          recalculate();
        }).fail(function() {
          $unit.prop('disabled', false);
        });
      }

      function addProduct(product, incrementExisting = false) {
        const existing = $(`#posSaleItems .pos-cart-line[data-product-id="${product.id}"]`);
        if (existing.length) {
          if (incrementExisting) {
            const $quantity = existing.find('.js-pos-quantity');
            const precision = Math.max(0, Math.min(6, Number(existing.find('.js-pos-unit').attr('data-selected-precision') || 0)));
            $quantity.val((Number($quantity.val() || 0) + 1).toFixed(precision)).trigger('input');
          }
          existing.addClass('is-highlighted');
          setTimeout(function() {
            existing.removeClass('is-highlighted');
          }, 900);
          return;
        }

        const html = $('#posSaleLineTemplate').html().replaceAll('__INDEX__', nextIndex++);
        const $line = $(html);
        $line.attr('data-product-id', product.id);
        $line.find('.js-pos-product-id').val(product.id);
        $line.find('.js-pos-product-name').val(product.name);
        $line.find('.js-pos-product-code').val(product.code);
        $line.find('.pos-cart-line__name').text(product.name).attr('title', product.name);
        const $stock = $line.find('.pos-cart-line__stock').removeClass('d-none');
        $stock.find('.js-pos-stock-quantity').text(product.stock_quantity);
        $stock.find('.js-pos-stock-unit').text(product.base_unit_code);
        $line.find('.js-pos-quantity').val('1');
        $('#posSaleItems').append($line);

        loadUnits($line, product.id, product.base_unit_id, product.retail_price, false);
        refreshCartState();
      }

      function barcodeNotice(icon, title, text) {
        if (window.Swal) {
          Swal.fire({ icon, title, text }).then(function() {
            $('#posBarcodeInput').trigger('focus');
          });
        } else {
          alert(text || title);
          $('#posBarcodeInput').trigger('focus');
        }
      }

      function scanBarcode() {
        const barcode = $('#posBarcodeInput').val().trim();
        const warehouseId = $('#posWarehouse').val();
        if (!barcode) {
          return;
        }
        if (!warehouseId) {
          barcodeNotice('warning', 'Select a warehouse first', 'Choose an issuing warehouse before scanning products.');
          $('#posBarcodeInput').val('').trigger('focus');
          return;
        }

        const $input = $('#posBarcodeInput').prop('disabled', true);
        $.getJSON(catalogUrl, { warehouse_id: warehouseId, barcode }).done(function(response) {
          const product = response.results?.[0];
          if (!product) {
            barcodeNotice('error', 'Product not found', 'No active Finished Product matches this barcode.');
            return;
          }
          if (!product.in_stock) {
            barcodeNotice('warning', 'Out of stock', 'This product has no available stock in the selected warehouse.');
            return;
          }

          addProduct(product, true);
        }).fail(function(xhr) {
          const message = xhr.responseJSON?.message || 'Unable to look up this barcode.';
          barcodeNotice('error', 'Barcode lookup failed', message);
        }).always(function() {
          $input.prop('disabled', false).val('').trigger('focus');
        });
      }

      function refreshCartState() {
        const count = $('#posSaleItems .pos-cart-line').length;
        $('#posSelectedItemCount').text(count);
        $('#emptyPosCart').toggleClass('d-none', count > 0);
        $('#posCartTableHeader').toggleClass('d-none', count === 0);
        $('#posSaleItems').toggleClass('d-none', count === 0);
        updateCatalogSelection();
        recalculate();
      }

      function recalculate() {
        let subtotal = 0;

        $('#posSaleItems .pos-cart-line').each(function() {
          const $line = $(this);
          const quantity = Number($line.find('.js-pos-quantity').val() || 0);
          const price = Number($line.find('.js-pos-price').val() || 0);
          const lineTotal = Math.max(quantity, 0) * Math.max(price, 0);
          $line.find('.js-pos-line-total').text(lineTotal.toFixed(2));
          subtotal += lineTotal;
        });

        const discountValue = Math.max(Number($('#posDiscountValue').val() || 0), 0);
        const discount = $('#posDiscountType').val() === 'PERCENT'
          ? subtotal * Math.min(discountValue, 100) / 100
          : Math.min(discountValue, subtotal);
        const tax = Math.max(Number($('#posTax').val() || 0), 0);
        const total = Math.max(subtotal - discount + tax, 0);

        $('#posSubtotalPreview').text(subtotal.toFixed(2));
        $('#posTotalPreview').text(total.toFixed(2));
        recalculateCheckout();
      }

      function selectedBranchId() {
        return Number($('#posWarehouse option:selected').data('branch-id') || 0);
      }

      function populatePaymentAccounts($select, selectedAccountId = '') {
        const branchId = selectedBranchId();
        $select.empty().append(new Option('Select account', ''));

        cashAccounts
          .filter(account => Number(account.branch_id) === branchId)
          .forEach(function(account) {
            const text = `${account.code} — ${account.name}`;
            $select.append(new Option(text, account.id, false, String(account.id) === String(selectedAccountId)));
          });
      }

      function addPaymentRow(defaultAmount = '') {
        const html = $('#posPaymentRowTemplate').html().replaceAll('__PAYMENT_INDEX__', nextPaymentIndex++);
        const $row = $(html);
        populatePaymentAccounts($row.find('.js-pos-payment-account'));
        $row.find('.js-pos-payment-amount').val(defaultAmount);
        $('#posPaymentRows').append($row);
        S2.auto($row);
        recalculateCheckout();
      }

      function setCheckoutFieldsEnabled(enabled) {
        $('#posPaymentRows')
          .find('select, input, button')
          .prop('disabled', !enabled);
      }

      function checkoutPaymentSummary(orderTotal) {
        let remaining = orderTotal;
        let applied = 0;
        let change = 0;
        let error = '';

        $('#posPaymentRows .pos-payment-row').each(function() {
          const $row = $(this);
          const tendered = Math.max(Number($row.find('.js-pos-payment-amount').val() || 0), 0);
          const $method = $row.find('.js-pos-payment-type option:selected');
          const allowsChange = Number($method.data('allows-change') || 0) === 1;

          if (tendered <= 0 || error) {
            return;
          }
          if (remaining <= 0.00001) {
            error = 'Remove unused payment rows after the order total has been fully allocated.';
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

      function recalculateCheckout() {
        const total = Math.max(Number($('#posTotalPreview').text() || 0), 0);
        const payment = checkoutPaymentSummary(total);
        const hasCustomer = Boolean($('#posCustomer').val());

        $('#checkoutOrderTotal').text(total.toFixed(2));
        $('#checkoutPaidTotal').text(payment.applied.toFixed(2));
        $('#checkoutDueTotal').text(payment.due.toFixed(2));
        $('#checkoutChangeTotal').text(payment.change.toFixed(2));
        $('#checkoutChangeCard').toggleClass('has-change', payment.change > 0.00001);

        const customerText = hasCustomer
          ? `${$('#posCustomer option:selected').text().trim()} · partial payment or due is allowed`
          : 'Walk-in Customer · full payment is required';
        $('#checkoutCustomerMessage').text(customerText);

        let warning = payment.error;
        if (!warning && !hasCustomer && payment.due > 0.00001) {
          warning = 'Collect full payment or select a registered Customer before completing this Sale.';
        }

        $('#posCheckoutWarning').text(warning).toggleClass('d-none', warning === '');
        $('#completePosCheckout').prop('disabled', warning !== '' || total <= 0);
      }

      function updateCustomerContext() {
        const customerId = $('#posCustomer').val();
        const customerLabel = customerId
          ? `${$('#posCustomer option:selected').text().trim()} · due is allowed`
          : 'Walk-in Customer · full payment required at checkout';
        const icon = customerId ? 'mdi:account-check-outline' : 'mdi:account-outline';

        $('#posCustomerContext span').text(customerLabel);
        $('#posCustomerContext iconify-icon').attr('icon', icon);
        recalculateCheckout();
      }

      function openCheckout() {
        if (!$('#posWarehouse').val()) {
          window.Swal
            ? Swal.fire({ icon: 'warning', title: 'Select a warehouse first' })
            : alert('Select a warehouse first.');
          return;
        }
        if (!$('#posSaleItems .pos-cart-line').length) {
          window.Swal
            ? Swal.fire({ icon: 'warning', title: 'Cart is empty', text: 'Add at least one Finished Product.' })
            : alert('Add at least one Finished Product.');
          return;
        }

        if (!$('#posPaymentRows .pos-payment-row').length) {
          addPaymentRow(Number($('#posTotalPreview').text() || 0).toFixed(2));
        }
        recalculateCheckout();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('posCheckoutModal')).show();
      }

      function applyWarehouseChange(warehouseId) {
        previousWarehouse = String(warehouseId || '');
        catalogPage = 1;
        loadCatalog(false);
      }

      $('#posWarehouse').on('change', function() {
        if (revertingWarehouse) {
          return;
        }

        const warehouseId = String($(this).val() || '');
        const hasItems = $('#posSaleItems .pos-cart-line').length > 0;
        if (!hasItems || !previousWarehouse || warehouseId === previousWarehouse) {
          applyWarehouseChange(warehouseId);
          $('#posPaymentRows .js-pos-payment-account').each(function() {
            populatePaymentAccounts($(this));
          });
          return;
        }

        const accept = function() {
          $('#posSaleItems').empty();
          refreshCartState();
          applyWarehouseChange(warehouseId);
        };
        const cancel = function() {
          revertingWarehouse = true;
          $('#posWarehouse').val(previousWarehouse).trigger('change.select2');
          revertingWarehouse = false;
        };

        if (window.Swal) {
          Swal.fire({
            icon: 'warning',
            title: 'Change issuing warehouse?',
            text: 'The current cart will be cleared because stock availability is warehouse-specific.',
            showCancelButton: true,
            confirmButtonText: 'Change and clear cart',
          }).then(function(result) {
            result.isConfirmed ? accept() : cancel();
          });
        } else if (confirm('Changing warehouse will clear the POS cart. Continue?')) {
          accept();
        } else {
          cancel();
        }
      });

      $('#posCatalogSearch').on('input', function() {
        clearTimeout(catalogSearchTimer);
        catalogSearchTimer = setTimeout(function() {
          loadCatalog(false);
        }, 280);
      });

      $('#posBarcodeInput').on('keydown', function(event) {
        if (event.key === 'Enter') {
          event.preventDefault();
          scanBarcode();
        }
      });

      $('.pos-category-pill').on('click', function() {
        $('.pos-category-pill').removeClass('active');
        $(this).addClass('active');
        $('#posCatalogCategory').val($(this).data('category-id'));
        loadCatalog(false);
      });

      $('#posCatalogLoadMore').on('click', function() {
        if (catalogHasMore) {
          catalogPage += 1;
          loadCatalog(true);
        }
      });

      $(document).on('click', '.pos-catalog-card:not(:disabled)', function() {
        addProduct($(this).data('product'));
      });

      $(document).on('click', '.btn-remove-pos-line', function() {
        $(this).closest('.pos-cart-line').remove();
        refreshCartState();
      });

      $(document).on('click', '.js-pos-quantity-step', function() {
        const $line = $(this).closest('.pos-cart-line');
        const $quantity = $line.find('.js-pos-quantity');
        const precision = Math.max(0, Math.min(6, Number($line.find('.js-pos-unit').attr('data-selected-precision') || 0)));
        const step = precision === 0 ? 1 : Number(`0.${'0'.repeat(precision - 1)}1`);
        const direction = Number($(this).data('direction'));
        const nextValue = Math.max(step, Number($quantity.val() || 0) + direction * step);
        $quantity.val(nextValue.toFixed(precision)).trigger('input');
      });

      $(document).on('change', '.js-pos-unit', function() {
        const $unit = $(this);
        const $option = $unit.find('option:selected');
        const $line = $unit.closest('.pos-cart-line');
        $unit.attr('data-selected-precision', Number($option.data('precision') ?? 6));
        if ($option.data('default-price') !== undefined && $option.data('default-price') !== '') {
          $line.find('.js-pos-price').val($option.data('default-price'));
        }
        recalculate();
      });

      $(document).on('input', '.js-pos-quantity, .js-pos-price, #posDiscountValue, #posTax', recalculate);
      $('#posDiscountType').on('change', recalculate);
      $('#posCustomer').on('change', updateCustomerContext);

      $(document).on('click', '.js-open-pos-checkout', openCheckout);

      $('#addPosPaymentRow').on('click', function() {
        addPaymentRow('');
      });

      $(document).on('click', '.pos-payment-remove', function() {
        $(this).closest('.pos-payment-row').remove();
        recalculateCheckout();
      });

      $(document).on('input change', '.js-pos-payment-amount, .js-pos-payment-type', recalculateCheckout);

      $('#posCheckoutModal')
        .on('show.bs.modal', function() {
          setCheckoutFieldsEnabled(true);
        })
        .on('hidden.bs.modal', function() {
          setCheckoutFieldsEnabled(false);
        });

      $('#clearPosItems').on('click', function() {
        if (!$('#posSaleItems .pos-cart-line').length) {
          return;
        }

        const clear = function() {
          $('#posSaleItems').empty();
          refreshCartState();
        };

        window.Swal
          ? Swal.fire({
              icon: 'question',
              title: 'Clear the POS cart?',
              showCancelButton: true,
              confirmButtonText: 'Clear All',
            }).then(function(result) {
              if (result.isConfirmed) {
                clear();
              }
            })
          : clear();
      });

      $('#posSaleForm').on('submit', function(event) {
        if (!$('#posSaleItems .pos-cart-line').length) {
          event.preventDefault();
          window.Swal
            ? Swal.fire({ icon: 'warning', title: 'Cart is empty', text: 'Add at least one Finished Product.' })
            : alert('Add at least one Finished Product.');
        }
      });

      $('#posSaleItems .pos-cart-line').each(function() {
        const $line = $(this);
        loadUnits(
          $line,
          $line.data('product-id'),
          $line.find('.js-pos-unit').val(),
          $line.find('.js-pos-price').val(),
          true,
        );
      });

      refreshCartState();
      S2.auto($('#posSaleForm'));
      updateCustomerContext();
      loadCatalog(false);
      $('#posBarcodeInput').trigger('focus');
    })(jQuery);
  </script>
@endsection
