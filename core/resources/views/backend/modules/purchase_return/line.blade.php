@php
  $line = is_array($line ?? null) ? $line : [];
  $sourceItemId = $line['purchase_receipt_item_id'] ?? null;
  $unitRate = isset($line['unit_rate']) && $line['unit_rate'] !== '' ? number_format((float) $line['unit_rate'], 2, '.', '') : '0.00';
@endphp
<div class="po-cart-line js-return-line" data-source-item-id="{{ $sourceItemId }}">
  <input type="hidden" name="lines[{{ $index }}][purchase_receipt_item_id]" class="js-return-source-item-id" value="{{ $sourceItemId }}">
  <div class="po-cart-line__fields">
    <div class="po-cart-field po-cart-field--item">
      <span class="po-row-label">Item</span>
      <div class="po-cart-line__identity">
        <span class="po-cart-line__type">{{ $line['domain_label'] ?? 'Inventory Item' }}</span>
        <strong class="po-cart-line__name" title="{{ $line['item_text'] ?? 'Select an item' }}">{{ $line['item_text'] ?? 'Select an item' }}</strong>
        <small class="receipt-line-pending">Maximum return: <span class="js-return-maximum-label">{{ $line['max_quantity'] ?? '0' }}</span> <span class="js-return-unit-code">{{ $line['unit_code'] ?? '' }}</span></small>
      </div>
    </div>
    <div class="po-cart-field po-cart-field--unit">
      <span class="po-row-label">Receipt Unit</span>
      <div class="po-line-total-box justify-content-start"><strong class="js-return-unit-text">{{ $line['unit_text'] ?? '-' }}</strong></div>
    </div>
    <div class="po-cart-field po-cart-field--quantity">
      <label class="po-row-label">Return Qty <span class="text-danger">*</span></label>
      <div class="po-quantity-control">
        <button type="button" class="po-quantity-step js-return-quantity-step" data-direction="-1" title="Decrease quantity"><iconify-icon icon="mdi:minus"></iconify-icon></button>
        <input type="number" name="lines[{{ $index }}][quantity]" class="form-control text-center js-return-quantity" min="0.000001" max="{{ $line['max_quantity'] ?? '' }}" step="0.000001" value="{{ $line['quantity_input'] ?? '' }}" placeholder="0" required>
        <button type="button" class="po-quantity-step js-return-quantity-step" data-direction="1" title="Increase quantity"><iconify-icon icon="mdi:plus"></iconify-icon></button>
      </div>
    </div>
    <div class="po-cart-field">
      <span class="po-row-label">Original Rate / Unit</span>
      <div class="po-line-total-box"><strong class="js-return-rate">{{ $unitRate }}</strong></div>
    </div>
    <div class="po-cart-field"><span class="po-row-label">Line Total</span><div class="po-line-total-box"><strong class="js-return-line-total">{{ isset($line['line_total']) ? number_format((float) $line['line_total'], 2) : '0.00' }}</strong></div></div>
    <div class="po-cart-field po-cart-field--action"><span class="po-row-label" aria-hidden="true">Action</span><button type="button" class="btn w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-remove-return-line" title="Remove item"><iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></button></div>
  </div>
</div>
