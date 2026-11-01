@extends('backend.layouts.master')

@php
  $editing = (bool) $return;
  $submittedLines = old('lines', $lines);
  $submittedLines = is_array($submittedLines) ? $submittedLines : [];
@endphp

@section('meta') <title>{{ $editing ? 'Edit' : 'Add' }} Purchase Return</title> @endsection
@section('body_class', 'purchase-order-screen purchase-return-screen')

@section('content')
<div class="purchase-order-screen-shell">
  @if (isset($errors) && $errors->any())<div class="alert alert-danger"><strong>Please correct the highlighted information.</strong><ul class="mb-0 mt-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  <form method="POST" action="{{ $editing ? route('purchase.returns.update', $return) : route('purchase.returns.store') }}" id="purchaseReturnForm">
    @csrf @if ($editing) @method('PUT') @endif
    <div class="purchase-order-workspace">
      <section class="card po-catalog-panel">
        <div class="card-header po-panel-header"><div><span class="po-panel-kicker">{{ $receipt ? 'Receipt '.$receipt->receipt_no : 'Purchase return source' }}</span><h5 class="card-title mb-0">Returnable Receipt Items</h5></div><span class="po-count-badge" id="returnCatalogCount">0 found</span></div>
        <div class="card-body po-catalog-body">
          <div class="po-catalog-controls">
            <div class="po-domain-tabs"><button type="button" class="po-domain-tab active" data-domain="raw_material"><iconify-icon icon="mdi:package-variant-closed"></iconify-icon><span>Raw Materials</span></button><button type="button" class="po-domain-tab" data-domain="part"><iconify-icon icon="mdi:memory"></iconify-icon><span>Parts</span></button></div>
            <div class="po-catalog-filters"><div class="po-search-box"><iconify-icon icon="mdi:magnify"></iconify-icon><input type="search" id="returnCatalogSearch" class="form-control" placeholder="Search name or code"></div></div>
          </div>
          <div class="po-catalog-scroll">
            @if (! $receipt)<div class="po-supplier-gate" id="returnReceiptGate"><span class="po-supplier-gate__icon"><iconify-icon icon="mdi:receipt-text-check-outline"></iconify-icon></span><strong>Select a posted receipt first</strong><span>Items that still have a returnable quantity will appear here.</span></div>@endif
            <div class="po-catalog-grid {{ $receipt ? '' : 'd-none' }}" id="returnCatalog" aria-live="polite"></div>
          </div>
        </div>
      </section>

      <section class="card po-order-panel">
        <div class="card-header po-panel-header"><div><span class="po-panel-kicker">Purchase return</span><div class="d-flex align-items-center gap-2"><h5 class="card-title mb-0">{{ $editing ? 'Edit Purchase Return' : 'Create Purchase Return' }}</h5><x-backend.page-note class="po-header-note" title="How purchase return works" label="View purchase return rules" align="start">Select items only from a posted receipt. The original receipt unit and rate are preserved. Saving a Draft does not change stock; Post Return permanently removes the returned base quantity from the receipt warehouse.</x-backend.page-note></div></div><span class="badge bg-warning-focus text-warning-600 px-12 py-7 radius-8">Draft</span></div>
        <div class="card-body po-order-body">
          <div class="row gy-3 po-order-meta-grid">
            <div class="col-12 po-order-meta-field"><label class="form-label">Source Purchase Receipt <span class="text-danger">*</span></label><input type="hidden" name="purchase_receipt_id" value="{{ $receipt?->id }}"><select id="returnReceipt" class="form-control form-control-sm js-s2" data-dropdown-css-class="po-select2-dropdown" {{ $editing ? 'disabled' : '' }}><option value="">Select a posted receipt</option>@foreach($receipts as $availableReceipt)<option value="{{ $availableReceipt->id }}" @selected((string)$receipt?->id === (string)$availableReceipt->id)>{{ $availableReceipt->receipt_no }} - {{ $availableReceipt->supplier->name }} - {{ $availableReceipt->receipt_date->format('d M Y') }}</option>@endforeach</select></div>
            <div class="col-md-6 po-order-meta-field"><label class="form-label">Supplier</label><input type="text" class="form-control form-control-sm" value="{{ $receipt?->supplier?->name ?? '' }}" placeholder="Selected from receipt" readonly></div>
            <div class="col-md-6 po-order-meta-field"><label class="form-label">Return Warehouse</label><input type="text" class="form-control form-control-sm" value="{{ $receipt ? $receipt->warehouse->name.' ('.$receipt->warehouse->code.')' : '' }}" placeholder="Selected from receipt" readonly></div>
            <div class="col-md-4 po-order-meta-field"><label class="form-label">Return Date <span class="text-danger">*</span></label><input type="date" name="return_date" class="form-control form-control-sm" min="{{ $receipt?->receipt_date?->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}" value="{{ old('return_date', $return?->return_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required></div>
            <div class="col-md-8"><label class="form-label">Return Reason <span class="text-danger">*</span></label><textarea name="reason" class="form-control" rows="2" maxlength="5000" placeholder="Example: damaged, wrong item or supplier replacement" required>{{ old('reason', $return?->reason) }}</textarea></div>
          </div>

          <div class="po-cart-section">
            <div class="po-cart-heading"><div><h6 class="mb-1 po-selected-items-title">Items to Return <span class="po-count-badge po-selected-count" id="returnSelectedCount">0</span></h6><p class="text-muted text-sm mb-0">Enter the physical quantity being sent back</p></div><button type="button" class="btn btn-outline-danger btn-sm px-10 radius-8" id="clearReturnItems"><iconify-icon icon="mdi:delete-sweep-outline"></iconify-icon><span>Clear All</span></button></div>
            <div class="po-empty-cart" id="emptyReturnCart"><span><iconify-icon icon="mdi:package-variant-remove"></iconify-icon></span><strong>No return items selected</strong><small>Choose returnable receipt items from the gallery on the left.</small></div>
            <div class="po-cart-table-header d-none" id="returnLineHeader"><span>Item</span><span>Receipt Unit</span><span>Return Qty</span><span>Original Rate</span><span>Line Total</span><span></span></div>
            <div class="po-cart-lines" id="returnLines">@foreach($submittedLines as $index => $line) @include('backend.modules.purchase_return.line', ['index' => $index, 'line' => $line]) @endforeach</div>
          </div>

          <div class="po-order-summary"><div class="po-summary-row po-summary-total"><span>Return Total</span><strong id="returnTotal">0.00</strong></div></div>
        </div>
        <div class="card-footer d-flex align-items-center justify-content-end gap-2"><a href="{{ route('purchase.returns.index') }}" class="btn btn-outline-secondary px-18 py-9 radius-8 app-action-button">Cancel</a><button type="submit" class="btn btn-primary px-18 py-9 radius-8 app-action-button app-primary-action po-save-draft"><iconify-icon icon="mdi:content-save-outline"></iconify-icon><span>Save Draft</span></button></div>
      </section>
    </div>
  </form>
</div>
<template id="returnLineTemplate">@include('backend.modules.purchase_return.line', ['index' => '__INDEX__', 'line' => []])</template>
@endsection

@section('script')
<script>
(function($) {
  const items = @json($catalogItems), catalogItems = new Map();
  let nextIndex = {{ count($submittedLines) }}, catalogDomain = 'raw_material', previousReceipt = String($('#returnReceipt').val() || '');
  function escapeHtml(value) { return $('<div>').text(value ?? '').html(); }
  function money(value) { return (Number.isFinite(value) ? value : 0).toFixed(2); }
  function lineSelector(item) { return `.js-return-line[data-source-item-id="${item.purchase_receipt_item_id}"]`; }
  function stepFor(precision) { precision = Math.max(0, Math.min(6, Number(precision) || 0)); return precision === 0 ? '1' : `0.${'0'.repeat(precision - 1)}1`; }
  function cardHtml(item) { return `<article class="po-catalog-card" data-key="${item.purchase_receipt_item_id}"><div class="po-catalog-card__image"><img src="${escapeHtml(item.image_url)}" alt=""><span>${escapeHtml(item.domain_label)}</span></div><div class="po-catalog-card__content"><strong title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</strong><code>${escapeHtml(item.code)}</code><div class="receipt-pending-stats"><span>Received <b>${escapeHtml(item.received_quantity)} ${escapeHtml(item.unit_code)}</b></span><span>Returned <b>${escapeHtml(item.returned_quantity)} ${escapeHtml(item.unit_code)}</b></span><span>Available <b>${escapeHtml(item.available_quantity)} ${escapeHtml(item.unit_code)}</b></span></div></div><button type="button" class="po-card-add"><iconify-icon icon="mdi:keyboard-return"></iconify-icon><span>Return</span></button></article>`; }
  function message(title, detail) { $('#returnCatalog').removeClass('d-none').html(`<div class="po-catalog-message"><span><iconify-icon icon="mdi:package-variant-remove"></iconify-icon></span><strong>${escapeHtml(title)}</strong><small>${escapeHtml(detail)}</small></div>`); $('#returnCatalogCount').text('0 found'); }
  function renderCatalog() { const search = $('#returnCatalogSearch').val().trim().toLowerCase(), filtered = items.filter(item => item.domain === catalogDomain && (!search || `${item.name} ${item.code}`.toLowerCase().includes(search))); catalogItems.clear(); $('#returnCatalog').empty(); filtered.forEach(item => { catalogItems.set(String(item.purchase_receipt_item_id), item); $('#returnCatalog').append(cardHtml(item)); }); if (!filtered.length) message('No returnable items found', 'Try another inventory type or search term.'); else $('#returnCatalogCount').text(`${filtered.length} found`); refreshState(); }
  function refreshState() { const count = $('#returnLines .js-return-line').length; $('#returnSelectedCount').text(count); $('#emptyReturnCart').toggleClass('d-none', count > 0); $('#returnLineHeader').toggleClass('d-none', count === 0); $('#clearReturnItems').prop('disabled', count === 0); $('.po-catalog-card').each(function() { const item = catalogItems.get(String($(this).data('key'))); if (!item) return; const selected = $(lineSelector(item)).length > 0; $(this).toggleClass('is-selected', selected).find('.po-card-add span').text(selected ? 'Added' : 'Return'); $(this).find('.po-card-add iconify-icon').attr('icon', selected ? 'mdi:check' : 'mdi:keyboard-return'); }); }
  function hydrateSubmittedLines() { $('#returnLines .js-return-line').each(function() { const $line = $(this), item = items.find(candidate => String(candidate.purchase_receipt_item_id) === String($line.data('source-item-id'))); if (!item) return; const step = stepFor(item.unit_precision); $line.find('.po-cart-line__type').text(item.domain_label); $line.find('.po-cart-line__name').text(`${item.name} (${item.code})`).attr('title', `${item.name} (${item.code})`); $line.find('.js-return-maximum-label').text(item.available_quantity); $line.find('.js-return-unit-code').text(item.unit_code); $line.find('.js-return-unit-text').text(item.unit_text); $line.find('.js-return-rate').text(money(Number(item.unit_rate))); $line.find('.js-return-quantity').attr({step, min:step, max:item.available_quantity}); }); }
  function recalc() { let total = 0; $('#returnLines .js-return-line').each(function() { const line = Number($(this).find('.js-return-quantity').val() || 0) * Number($(this).find('.js-return-rate').text() || 0); total += Number.isFinite(line) ? line : 0; $(this).find('.js-return-line-total').text(money(line)); }); $('#returnTotal').text(money(total)); refreshState(); }
  function addItem(item) { const $existing = $(lineSelector(item)); if ($existing.length) { $existing[0].scrollIntoView({behavior:'smooth',block:'center'}); return; } const $line = $($('#returnLineTemplate').html().replaceAll('__INDEX__', nextIndex++)).appendTo('#returnLines'), step = stepFor(item.unit_precision); $line.attr('data-source-item-id', item.purchase_receipt_item_id); $line.find('.js-return-source-item-id').val(item.purchase_receipt_item_id); $line.find('.po-cart-line__type').text(item.domain_label); $line.find('.po-cart-line__name').text(`${item.name} (${item.code})`).attr('title', `${item.name} (${item.code})`); $line.find('.js-return-maximum-label').text(item.available_quantity); $line.find('.js-return-unit-code').text(item.unit_code); $line.find('.js-return-unit-text').text(item.unit_text); $line.find('.js-return-rate').text(money(Number(item.unit_rate))); $line.find('.js-return-quantity').attr({step, min:step, max:item.available_quantity}).val(step === '1' ? 1 : step); recalc(); $line[0].scrollIntoView({behavior:'smooth',block:'nearest'}); }
  function clearLines() { $('#returnLines').empty(); recalc(); }

  S2.auto($('#purchaseReturnForm')); hydrateSubmittedLines(); renderCatalog(); recalc();
  $('#returnReceipt').on('change', function() { const next = String($(this).val() || ''), navigate = () => { window.location.href = @json(route('purchase.returns.create')) + (next ? `?purchase_receipt_id=${encodeURIComponent(next)}` : ''); }; if (next === previousReceipt) return; if ($('#returnLines .js-return-line').length && window.Swal) Swal.fire({icon:'question',title:'Change source receipt?',text:'Current return items will be cleared.',showCancelButton:true,confirmButtonText:'Yes, change'}).then(result => { if (result.isConfirmed) navigate(); else $(this).val(previousReceipt).trigger('change.select2'); }); else navigate(); });
  $('.po-domain-tab').on('click', function() { catalogDomain = $(this).data('domain'); $('.po-domain-tab').removeClass('active'); $(this).addClass('active'); $('#returnCatalogSearch').val(''); renderCatalog(); });
  $('#returnCatalogSearch').on('input', renderCatalog);
  $(document).on('click', '#returnCatalog .po-catalog-card, #returnCatalog .po-card-add', function(event) { event.preventDefault(); event.stopPropagation(); const item = catalogItems.get(String($(this).closest('.po-catalog-card').data('key'))); if (item) addItem(item); });
  $(document).on('input change', '.js-return-quantity', function() { const max = Number($(this).attr('max') || 0), value = Number($(this).val() || 0); if (max > 0 && value > max) $(this).val(max); recalc(); });
  $(document).on('click', '.js-return-quantity-step', function() { const $input = $(this).siblings('.js-return-quantity'), step = Number($input.attr('step') || 1), max = Number($input.attr('max') || 0), current = Number($input.val() || 0), decimals = (String(step).split('.')[1] || '').length; let next = Math.max(step, current + Number($(this).data('direction')) * step); if (max > 0) next = Math.min(max, next); $input.val(next.toFixed(decimals)).trigger('input'); });
  $(document).on('click', '.btn-remove-return-line', function() { $(this).closest('.js-return-line').remove(); recalc(); });
  $('#clearReturnItems').on('click', function() { if (!$('#returnLines .js-return-line').length) return; if (window.Swal) Swal.fire({icon:'question',title:'Clear return items?',showCancelButton:true,confirmButtonText:'Yes, clear',confirmButtonColor:'#dc3545'}).then(result => { if (result.isConfirmed) clearLines(); }); else if (confirm('Clear all return items?')) clearLines(); });
})(jQuery);
</script>
@endsection
