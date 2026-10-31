@extends('backend.layouts.master')

@php
  $editing = (bool) $order;
  $submittedLines = old('lines', $lines);
  $submittedLines = is_array($submittedLines) ? $submittedLines : [];
@endphp

@section('meta') <title>{{ $editing ? 'Edit' : 'Add' }} Purchase Order</title> @endsection
@section('body_class', 'purchase-order-screen')

@section('content')
  <div class="purchase-order-screen-shell">
    @if ($errors->any())<div class="alert alert-danger"><strong>Please correct the highlighted information.</strong><ul class="mb-0 mt-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <form method="POST" action="{{ $editing ? route('purchase.orders.update', $order) : route('purchase.orders.store') }}" id="purchaseOrderForm">
    @csrf @if ($editing) @method('PUT') @endif

    <div class="purchase-order-workspace">
      <section class="card po-catalog-panel">
        <div class="card-header po-panel-header">
          <div>
            <span class="po-panel-kicker">Purchase catalogue</span>
            <h5 class="card-title mb-0">Select Purchase Items</h5>
          </div>
          <span class="po-count-badge" id="catalogResultCount">0 found</span>
        </div>

        <div class="card-body po-catalog-body">
          <div class="po-catalog-controls">
            <div class="po-domain-tabs" role="tablist" aria-label="Inventory type">
              <button type="button" class="po-domain-tab active" data-domain="raw_material"><iconify-icon icon="mdi:package-variant-closed"></iconify-icon><span>Raw Materials</span></button>
              <button type="button" class="po-domain-tab" data-domain="part"><iconify-icon icon="mdi:memory"></iconify-icon><span>Parts</span></button>
            </div>

            <div class="po-catalog-filters">
              <div class="po-search-box"><iconify-icon icon="mdi:magnify"></iconify-icon><input type="search" id="catalogSearch" class="form-control" placeholder="Search name, code or supplier code"></div>
              <select id="catalogCategory" class="form-select" aria-label="Filter by category"><option value="">All categories</option></select>
            </div>
          </div>

          <div class="po-catalog-scroll">
            <div class="po-supplier-gate" id="supplierGate">
              <span class="po-supplier-gate__icon"><iconify-icon icon="mdi:truck-check-outline"></iconify-icon></span>
              <strong>Select a supplier first</strong>
              <span>All available purchase items will be shown; mapped items will be highlighted.</span>
            </div>

            <div class="po-catalog-grid d-none" id="purchaseCatalog" aria-live="polite"></div>
            <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-3 d-none" id="catalogLoadMore"><iconify-icon icon="mdi:chevron-down"></iconify-icon><span>Load more items</span></button>
          </div>
        </div>
      </section>

      <section class="card po-order-panel">
        <div class="card-header po-panel-header">
          <div>
            <span class="po-panel-kicker">Purchase order</span>
            <div class="d-flex align-items-center gap-2">
              <h5 class="card-title mb-0">{{ $editing ? 'Edit Purchase Order' : 'Create Purchase Order' }}</h5>
              <x-backend.page-note class="po-header-note" title="How purchase order works" label="View purchase order rules" align="start">
                Select a supplier, then add any available Raw Material or purchasable Part. Supplier mapping is optional and mapped items are highlighted. Saving a Draft does not change stock or accounts.
              </x-backend.page-note>
            </div>
          </div>
          <span class="badge bg-warning-focus text-warning-600 px-12 py-7 radius-8">Draft</span>
        </div>

        <div class="card-body po-order-body">
          <div class="row gy-3 po-order-meta-grid">
            <div class="col-12 po-order-meta-field">
              <label class="form-label">Supplier <span class="text-danger">*</span></label>
              <div class="po-supplier-picker">
                <select name="supplier_id" id="purchaseSupplier" class="form-control form-control-sm js-s2" placeholder="Select supplier" required><option value=""></option>
                  @foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) old('supplier_id', $order?->supplier_id) === (string) $supplier->id)>{{ $supplier->name }}{{ $supplier->code ? ' ('.$supplier->code.')' : '' }}</option>@endforeach
                </select>
                @perm('supplier.store', 'add')
                  <button type="button" class="btn po-supplier-add AjaxModal" data-ajax-modal="{{ route('supplier.createModal') }}" data-size="lg" data-onsuccess="PurchaseOrderForm.onSupplierCreated" title="Add supplier" aria-label="Add supplier"><iconify-icon icon="mdi:account-plus-outline"></iconify-icon></button>
                @endperm
              </div>
            </div>
            <div class="col-md-6 po-order-meta-field">
              <label class="form-label">Receiving Warehouse <span class="text-danger">*</span></label>
              <select name="warehouse_id" class="form-control form-control-sm js-s2" placeholder="Select warehouse" required><option value=""></option>
                @foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id', $order?->warehouse_id) === (string) $warehouse->id)>{{ $warehouse->name }} ({{ $warehouse->code }}) - {{ $warehouse->branch_name }}</option>@endforeach
              </select>
            </div>
            <div class="col-md-3 po-order-meta-field">
              <label class="form-label">Order Date <span class="text-danger">*</span></label>
              <input type="date" name="order_date" class="form-control form-control-sm" value="{{ old('order_date', $order?->order_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required>
            </div>
            <div class="col-md-3 po-order-meta-field">
              <label class="form-label">Expected Date</label>
              <input type="date" name="expected_date" class="form-control form-control-sm" value="{{ old('expected_date', $order?->expected_date?->format('Y-m-d')) }}">
            </div>
            <div class="col-12"><label class="form-label">Order Note</label><textarea name="note" class="form-control" rows="2" maxlength="5000" placeholder="Optional supplier or delivery instruction">{{ old('note', $order?->note) }}</textarea></div>
          </div>

          <div class="po-cart-section">
            <div class="po-cart-heading">
              <div><h6 class="mb-1 po-selected-items-title">Selected Items <span class="po-count-badge po-selected-count" id="selectedItemCount">0</span></h6><p class="text-muted text-sm mb-0">Set purchase unit, quantity and supplier rate</p></div>
              <button type="button" class="btn btn-outline-danger btn-sm px-10 radius-8" id="clearPurchaseItems"><iconify-icon icon="mdi:delete-sweep-outline"></iconify-icon><span>Clear All</span></button>
            </div>

            <div class="po-empty-cart" id="emptyPurchaseCart"><span><iconify-icon icon="mdi:cart-arrow-down"></iconify-icon></span><strong>No items selected</strong><small>Choose items from the gallery on the left.</small></div>
            <div class="po-cart-table-header d-none" id="purchaseOrderLineHeader" aria-hidden="true">
              <span>Item</span><span>Purchase Unit</span><span>Quantity</span><span>Rate / Unit</span><span>Line Total</span><span></span>
            </div>
            <div class="po-cart-lines" id="purchaseOrderLines">@foreach ($submittedLines as $index => $line) @include('backend.modules.purchase_order.line', ['index' => $index, 'line' => $line]) @endforeach</div>
          </div>

          <div class="po-order-summary">
            <div class="po-summary-row"><span>Subtotal</span><strong id="poSubtotalPreview">0.00</strong></div>
            <div class="po-summary-input"><label for="poDiscountValue">Discount</label><div class="po-discount-control">
              <select name="discount_type" id="poDiscountType" class="form-select" aria-label="Discount type">
                <option value="FLAT" @selected(old('discount_type', $order?->discount_type ?? 'FLAT') === 'FLAT')>Flat</option>
                <option value="PERCENT" @selected(old('discount_type', $order?->discount_type ?? 'FLAT') === 'PERCENT')>%</option>
              </select>
              <input type="number" name="discount_value" id="poDiscountValue" class="form-control text-end" min="0" step="0.01" value="{{ old('discount_value', number_format((float) ($order?->discount_value ?? $order?->discount ?? 0), 2, '.', '')) }}">
            </div></div>
            <div class="po-summary-input"><label for="poTax">Tax</label><input type="number" name="tax" id="poTax" class="form-control text-end" min="0" step="0.01" value="{{ old('tax', number_format((float) ($order?->tax ?? 0), 2, '.', '')) }}"></div>
            <div class="po-summary-row po-summary-total"><span>Order Total</span><strong id="poTotalPreview">0.00</strong></div>
          </div>
        </div>

        <div class="card-footer d-flex align-items-center justify-content-end gap-2">
          <a href="{{ route('purchase.orders.index') }}" class="btn btn-outline-secondary px-18 py-9 radius-8 app-action-button"><span>Cancel</span></a>
          <button type="submit" class="btn btn-primary px-18 py-9 radius-8 app-action-button app-primary-action po-save-draft"><iconify-icon icon="mdi:content-save-outline"></iconify-icon><span>Save Draft</span></button>
        </div>
      </section>
    </div>
    </form>
  </div>

  <template id="purchaseOrderLineTemplate">@include('backend.modules.purchase_order.line', ['index' => '__INDEX__', 'line' => []])</template>
@endsection

@section('script')
<script>
  (function($) {
    const itemUrl = @json(route('purchase.orders.items.select2'));
    const unitUrl = @json(route('purchase.orders.units.select2'));
    const categorySets = @json($catalogCategories);
    let nextIndex = {{ count($submittedLines) }};
    let catalogDomain = 'raw_material';
    let catalogPage = 1;
    let catalogHasMore = false;
    let catalogLoading = false;
    let previousSupplier = String($('#purchaseSupplier').val() || '');
    let restoringSupplier = false;
    let searchTimer;
    const catalogItems = new Map();

    window.PurchaseOrderForm = {
      onSupplierCreated: function(response) {
        const supplier = response?.supplier;
        if (!supplier?.id) return;
        const text = supplier.code ? `${supplier.name} (${supplier.code})` : supplier.name;
        const $supplier = $('#purchaseSupplier');
        $supplier.find(`option[value="${supplier.id}"]`).remove();
        restoringSupplier = true;
        $supplier.append(new Option(text, supplier.id, true, true)).trigger('change.select2');
        restoringSupplier = false;
        acceptSupplierChange(String(supplier.id));
      }
    };

    function escapeHtml(value) { return $('<div>').text(value ?? '').html(); }
    function money(value) { return (Number.isFinite(value) ? value : 0).toFixed(2); }
    function cartKey(domain, itemId) { return `${domain}:${itemId}`; }
    function lineSelector(domain, itemId) { return `.po-cart-line[data-domain="${domain}"][data-item-id="${itemId}"]`; }
    function cartHas(domain, itemId) { return $(lineSelector(domain, itemId)).length > 0; }

    function applyUnitPrecision($line, precision) {
      precision = Math.max(0, Math.min(6, Number.isFinite(precision) ? precision : 6));
      const step = precision === 0 ? '1' : `0.${'0'.repeat(precision - 1)}1`;
      $line.find('.js-po-quantity').attr({step, min: step});
    }
    function initLine($line) {
      const $unit = $line.find('.js-po-unit');
      S2.init($unit, {ajax: {url: unitUrl, dataType: 'json', delay: 200, data: params => ({q: params.term || '', domain: $line.data('domain'), item_id: $line.data('itemId')}), processResults: data => ({results: data.results || []})}});
      applyUnitPrecision($line, Number($unit.data('selectedPrecision') ?? 6));
    }
    function refreshCartState() {
      const count = $('#purchaseOrderLines .po-cart-line').length;
      $('#selectedItemCount').text(count);
      $('#emptyPurchaseCart').toggleClass('d-none', count > 0);
      $('#purchaseOrderLineHeader').toggleClass('d-none', count === 0);
      $('#clearPurchaseItems').prop('disabled', count === 0);
      $('.po-catalog-card').each(function() {
        const selected = cartHas($(this).data('domain'), $(this).data('itemId'));
        $(this).toggleClass('is-selected', selected).find('.po-card-add span').text(selected ? 'Added' : 'Add');
        $(this).find('.po-card-add iconify-icon').attr('icon', selected ? 'mdi:check' : 'mdi:plus');
      });
    }
    function recalculate() {
      let subtotal = 0;
      $('#purchaseOrderLines .po-cart-line').each(function() {
        const quantity = Number($(this).find('.js-po-quantity').val() || 0);
        const rate = Number($(this).find('.js-po-rate').val() || 0);
        const total = quantity * rate;
        subtotal += Number.isFinite(total) ? total : 0;
        $(this).find('.js-po-line-total').text(money(total));
      });
      const isPercentageDiscount = $('#poDiscountType').val() === 'PERCENT';
      $('#poDiscountValue').attr('max', isPercentageDiscount ? '100' : '9999999999999999.99');
      const discountValue = Math.max(0, Number($('#poDiscountValue').val() || 0));
      const discount = isPercentageDiscount ? subtotal * Math.min(discountValue, 100) / 100 : discountValue;
      const tax = Number($('#poTax').val() || 0);
      $('#poSubtotalPreview').text(money(subtotal));
      $('#poTotalPreview').text(money(Math.max(0, subtotal - discount + tax)));
      refreshCartState();
    }

    function renderCategories() {
      const categories = categorySets[catalogDomain] || [];
      const $select = $('#catalogCategory').empty().append(new Option('All categories', ''));
      categories.forEach(category => $select.append(new Option(category.name, category.id)));
    }
    function catalogueMessage(icon, title, message) {
      $('#purchaseCatalog').removeClass('d-none').html(`<div class="po-catalog-message"><span><iconify-icon icon="${icon}"></iconify-icon></span><strong>${escapeHtml(title)}</strong><small>${escapeHtml(message)}</small></div>`);
      $('#catalogLoadMore').addClass('d-none');
      $('#catalogResultCount').text('0 found');
    }
    function cardHtml(item) {
      const mapped = item.is_mapped ? '<span class="po-mapped-badge"><iconify-icon icon="mdi:link-variant"></iconify-icon> Mapped</span>' : '';
      const preferred = item.is_preferred ? '<span class="po-preferred-badge"><iconify-icon icon="mdi:star"></iconify-icon> Preferred</span>' : '';
      const supplierCode = item.supplier_code ? `<small>Supplier code: ${escapeHtml(item.supplier_code)}</small>` : '';
      return `<article class="po-catalog-card" data-key="${escapeHtml(cartKey(item.domain, item.id))}" data-domain="${escapeHtml(item.domain)}" data-item-id="${Number(item.id)}">
        <div class="po-catalog-card__image"><img src="${escapeHtml(item.image_url)}" alt=""><span>${escapeHtml(item.domain_label)}</span></div>
        <div class="po-catalog-card__content"><div class="po-card-badges">${mapped}${preferred}</div><strong title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</strong><code>${escapeHtml(item.code)}</code><p>${escapeHtml(item.category_name)} · Base: ${escapeHtml(item.unit_code)}</p>${supplierCode}</div>
        <button type="button" class="po-card-add" aria-label="Add ${escapeHtml(item.name)}"><iconify-icon icon="mdi:plus"></iconify-icon><span>Add</span></button>
      </article>`;
    }
    function loadCatalog(append = false) {
      const supplierId = $('#purchaseSupplier').val();
      if (!supplierId) {
        $('#supplierGate').removeClass('d-none'); $('#purchaseCatalog').addClass('d-none').empty(); $('#catalogLoadMore').addClass('d-none'); $('#catalogResultCount').text('0 found'); return;
      }
      if (catalogLoading) return;
      catalogLoading = true;
      if (!append) {
        catalogPage = 1; catalogItems.clear(); $('#purchaseCatalog').removeClass('d-none').html('<div class="po-catalog-loading"><span></span><span></span><span></span><span></span></div>');
      }
      $('#supplierGate').addClass('d-none');
      $.get(itemUrl, {supplier_id: supplierId, domain: catalogDomain, category_id: $('#catalogCategory').val(), q: $('#catalogSearch').val().trim(), page: catalogPage})
        .done(function(response) {
          const items = response.results || [];
          catalogHasMore = Boolean(response.pagination?.more);
          if (!append) $('#purchaseCatalog').empty();
          items.forEach(item => { catalogItems.set(cartKey(item.domain, item.id), item); $('#purchaseCatalog').append(cardHtml(item)); });
          if (!$('#purchaseCatalog .po-catalog-card').length) catalogueMessage('mdi:package-variant-remove', 'No purchasable items found', 'Try another category or search term.');
          else $('#catalogResultCount').text(`${$('#purchaseCatalog .po-catalog-card').length}${catalogHasMore ? '+' : ''} found`);
          $('#catalogLoadMore').toggleClass('d-none', !catalogHasMore);
          refreshCartState();
        })
        .fail(function() { catalogueMessage('mdi:alert-circle-outline', 'Could not load items', 'Please refresh and try again.'); })
        .always(function() { catalogLoading = false; });
    }
    function addCatalogItem(item) {
      const selector = lineSelector(item.domain, item.id);
      if ($(selector).length) {
        $(selector)[0].scrollIntoView({behavior: 'smooth', block: 'center'});
        $(selector).addClass('is-highlighted'); setTimeout(() => $(selector).removeClass('is-highlighted'), 900); return;
      }
      const $line = $($('#purchaseOrderLineTemplate').html().replaceAll('__INDEX__', nextIndex++)).appendTo('#purchaseOrderLines');
      $line.attr({'data-domain': item.domain, 'data-item-id': item.id}).data('domain', item.domain).data('itemId', item.id);
      $line.find('.js-po-domain').val(item.domain);
      $line.find('.js-po-item-id').val(item.id);
      $line.find('.po-cart-line__type').text(item.domain_label);
      $line.find('.po-cart-line__name').text(`${item.name} (${item.code})`).attr('title', `${item.name} (${item.code})`);
      const $unit = $line.find('.js-po-unit');
      $unit.append(new Option(`${item.unit_code} - Base unit`, item.base_unit_id, true, true)).data('selectedPrecision', item.unit_precision);
      $line.find('.js-po-quantity').val('1');
      initLine($line); recalculate();
      $line[0].scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }
    function clearLines() { $('#purchaseOrderLines').empty(); recalculate(); }
    function acceptSupplierChange(nextSupplier) {
      previousSupplier = String(nextSupplier || ''); clearLines(); $('#catalogSearch').val(''); $('#catalogCategory').val(''); loadCatalog();
    }

    S2.auto($('#purchaseOrderForm'));
    $('#purchaseOrderLines .po-cart-line').each(function() { initLine($(this)); });
    renderCategories(); recalculate(); loadCatalog();

    $('#purchaseSupplier').on('change', function() {
      if (restoringSupplier) return;
      const nextSupplier = String($(this).val() || '');
      if (nextSupplier === previousSupplier) return;
      if ($('#purchaseOrderLines .po-cart-line').length && previousSupplier) {
        const proceed = () => acceptSupplierChange(nextSupplier);
        const restore = () => { restoringSupplier = true; $('#purchaseSupplier').val(previousSupplier).trigger('change.select2'); restoringSupplier = false; };
        if (window.Swal) Swal.fire({icon: 'warning', title: 'Change supplier?', text: 'Selected items belong to the current supplier and will be cleared.', showCancelButton: true, confirmButtonText: 'Change supplier', confirmButtonColor: '#d97706'}).then(result => result.isConfirmed ? proceed() : restore());
        else if (confirm('Changing supplier will clear selected items. Continue?')) proceed(); else restore();
      } else acceptSupplierChange(nextSupplier);
    });
    $('.po-domain-tab').on('click', function() { $('.po-domain-tab').removeClass('active'); $(this).addClass('active'); catalogDomain = $(this).data('domain'); renderCategories(); loadCatalog(); });
    $('#catalogCategory').on('change', () => loadCatalog());
    $('#catalogSearch').on('input', function() { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadCatalog(), 280); });
    $('#catalogLoadMore').on('click', function() { if (catalogHasMore) { catalogPage += 1; loadCatalog(true); } });
    $(document).on('click', '.po-catalog-card', function(event) { event.preventDefault(); const item = catalogItems.get($(this).data('key')); if (item) addCatalogItem(item); });
    $(document).on('select2:select', '.js-po-unit', function(event) { applyUnitPrecision($(this).closest('.po-cart-line'), Number(event.params?.data?.precision ?? 6)); });
    $(document).on('click', '.js-po-quantity-step', function() {
      const $input = $(this).siblings('.js-po-quantity');
      const stepText = String($input.attr('step') || '1');
      const step = Number(stepText) > 0 ? Number(stepText) : 1;
      const minimum = Number($input.attr('min') || step);
      const direction = Number($(this).data('direction')) < 0 ? -1 : 1;
      const current = Number($input.val());
      const next = Math.max(minimum, (Number.isFinite(current) ? current : 0) + (direction * step));
      const decimals = stepText.includes('.') ? stepText.split('.')[1].length : 0;
      $input.val(next.toFixed(decimals)).trigger('input');
    });
    $(document).on('input change', '.js-po-quantity, .js-po-rate, #poDiscountValue, #poDiscountType, #poTax', recalculate);
    $(document).on('click', '.btn-remove-po-line', function() { $(this).closest('.po-cart-line').remove(); recalculate(); });
    $('#clearPurchaseItems').on('click', function() {
      if (!$('#purchaseOrderLines .po-cart-line').length) return;
      if (window.Swal) Swal.fire({icon: 'question', title: 'Clear selected items?', showCancelButton: true, confirmButtonText: 'Yes, clear', confirmButtonColor: '#dc3545'}).then(result => { if (result.isConfirmed) clearLines(); });
      else if (confirm('Clear all selected items?')) clearLines();
    });
    $('#purchaseOrderForm').on('submit', function(event) {
      if ($('#purchaseOrderLines .po-cart-line').length) return;
      event.preventDefault();
      if (window.Swal) Swal.fire({icon: 'warning', title: 'No items selected', text: 'Choose at least one Raw Material or Part from the gallery.'});
      else alert('Choose at least one item from the gallery.');
    });
  })(jQuery);
</script>
@endsection
