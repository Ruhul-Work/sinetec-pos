@php
  $line = is_array($line ?? null) ? $line : [];
  $domain = $line['domain'] ?? 'raw_material';
  $itemId = $line['item_id'] ?? null;
  $unitId = $line['unit_id'] ?? null;
  $domainLabel = $line['domain_label'] ?? ($domains[$domain]['label'] ?? 'Item');
  $itemText = $line['item_text'] ?? ($itemId ? 'Item #'.$itemId : 'Select an item');
  $unitRateValue = isset($line['unit_rate']) && $line['unit_rate'] !== ''
    ? number_format((float) $line['unit_rate'], 2, '.', '')
    : '';
@endphp
<div class="po-cart-line" data-domain="{{ $domain }}" data-item-id="{{ $itemId }}">
  <input type="hidden" name="lines[{{ $index }}][domain]" class="js-po-domain" value="{{ $domain }}">
  <input type="hidden" name="lines[{{ $index }}][item_id]" class="js-po-item-id" value="{{ $itemId }}">

  <div class="po-cart-line__fields">
    <div class="po-cart-field po-cart-field--item">
      <span class="po-row-label">Item</span>
    <div class="po-cart-line__identity">
      <span class="po-cart-line__type">{{ $domainLabel }}</span>
      <strong class="po-cart-line__name" title="{{ $itemText }}">{{ $itemText }}</strong>
    </div>
    </div>
    <div class="po-cart-field po-cart-field--unit">
      <label class="po-row-label">Purchase Unit <span class="text-danger">*</span></label>
      <select name="lines[{{ $index }}][unit_id]" class="form-control js-po-unit" data-selected-precision="{{ $line['unit_precision'] ?? 6 }}" data-placeholder="Select unit" required>
        @if ($unitId)<option value="{{ $unitId }}" selected>{{ $line['unit_text'] ?? ('Unit #'.$unitId) }}</option>@endif
      </select>
    </div>
    <div class="po-cart-field po-cart-field--quantity">
      <label class="po-row-label">Quantity <span class="text-danger">*</span></label>
      <div class="po-quantity-control">
        <button type="button" class="po-quantity-step js-po-quantity-step" data-direction="-1" title="Decrease quantity" aria-label="Decrease quantity"><iconify-icon icon="mdi:minus"></iconify-icon></button>
        <input type="number" name="lines[{{ $index }}][quantity]" class="form-control text-center js-po-quantity" min="0.000001" step="0.000001" value="{{ $line['quantity_input'] ?? $line['quantity'] ?? '' }}" placeholder="0" required>
        <button type="button" class="po-quantity-step js-po-quantity-step" data-direction="1" title="Increase quantity" aria-label="Increase quantity"><iconify-icon icon="mdi:plus"></iconify-icon></button>
      </div>
    </div>
    <div class="po-cart-field">
      <label class="po-row-label">Rate / Unit <span class="text-danger">*</span></label>
      <input type="number" name="lines[{{ $index }}][unit_rate]" class="form-control text-end js-po-rate" min="0.01" step="0.01" value="{{ $unitRateValue }}" placeholder="0.00" required>
    </div>
    <div class="po-cart-field">
      <span class="po-row-label">Line Total</span>
      <div class="po-line-total-box"><strong class="js-po-line-total">{{ isset($line['line_total']) ? number_format((float) $line['line_total'], 2) : '0.00' }}</strong></div>
    </div>
    <div class="po-cart-field po-cart-field--action">
      <span class="po-row-label" aria-hidden="true">Action</span>
      <button type="button" class="btn w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-remove-po-line" title="Remove item">
        <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
      </button>
    </div>
  </div>
</div>
