@php
  $line = is_array($line ?? null) ? $line : [];
  $domain = $line['domain'] ?? 'raw_material';
  $itemId = $line['item_id'] ?? null;
  $unitId = $line['unit_id'] ?? null;
  $orderItemId = $line['purchase_order_item_id'] ?? null;
  $domainLabel = $line['domain_label'] ?? ($domain === 'part' ? 'Part' : 'Raw Material');
  $itemText = $line['item_text'] ?? ($itemId ? 'Item #'.$itemId : 'Select an item');
  $unitRate = isset($line['unit_rate']) && $line['unit_rate'] !== '' ? number_format((float) $line['unit_rate'], 2, '.', '') : '';
@endphp
<div class="po-cart-line js-receipt-line" data-domain="{{ $domain }}" data-item-id="{{ $itemId }}" data-order-item-id="{{ $orderItemId }}">
  <input type="hidden" name="lines[{{ $index }}][purchase_order_item_id]" class="js-receipt-order-item-id" value="{{ $orderItemId }}">
  <input type="hidden" name="lines[{{ $index }}][domain]" class="js-receipt-domain" value="{{ $domain }}">
  <input type="hidden" name="lines[{{ $index }}][item_id]" class="js-receipt-item-id" value="{{ $itemId }}">
  <div class="po-cart-line__fields">
    <div class="po-cart-field po-cart-field--item">
      <span class="po-row-label">Item</span>
      <div class="po-cart-line__identity">
        <span class="po-cart-line__type">{{ $domainLabel }}</span>
        <strong class="po-cart-line__name" title="{{ $itemText }}">{{ $itemText }}</strong>
        <small class="receipt-line-pending {{ $orderItemId ? '' : 'd-none' }}">Pending: <span class="js-receipt-pending">{{ isset($line['pending_base_quantity']) ? rtrim(rtrim(number_format((float) $line['pending_base_quantity'], 6, '.', ''), '0'), '.') : '0' }}</span> <span class="js-receipt-base-unit">{{ $line['base_unit_code'] ?? '' }}</span></small>
      </div>
    </div>
    <div class="po-cart-field po-cart-field--unit">
      <label class="po-row-label">Purchase Unit <span class="text-danger">*</span></label>
      <select name="lines[{{ $index }}][unit_id]" class="form-control js-receipt-unit" data-selected-precision="{{ $line['unit_precision'] ?? 6 }}" data-placeholder="Select unit" required>
        @if ($unitId)<option value="{{ $unitId }}" selected>{{ $line['unit_text'] ?? ('Unit #'.$unitId) }}</option>@endif
      </select>
    </div>
    <div class="po-cart-field po-cart-field--quantity">
      <label class="po-row-label">Received Qty <span class="text-danger">*</span></label>
      <div class="po-quantity-control">
        <button type="button" class="po-quantity-step js-receipt-quantity-step" data-direction="-1" title="Decrease quantity"><iconify-icon icon="mdi:minus"></iconify-icon></button>
        <input type="number" name="lines[{{ $index }}][quantity]" class="form-control text-center js-receipt-quantity" min="0.000001" step="0.000001" value="{{ $line['quantity_input'] ?? $line['quantity'] ?? '' }}" placeholder="0" required>
        <button type="button" class="po-quantity-step js-receipt-quantity-step" data-direction="1" title="Increase quantity"><iconify-icon icon="mdi:plus"></iconify-icon></button>
      </div>
    </div>
    <div class="po-cart-field">
      <label class="po-row-label">Actual Rate / Unit <span class="text-danger">*</span></label>
      <input type="number" name="lines[{{ $index }}][unit_rate]" class="form-control text-end js-receipt-rate js-po-rate" min="0.01" step="0.01" value="{{ $unitRate }}" placeholder="0.00" required>
    </div>
    <div class="po-cart-field"><span class="po-row-label">Line Total</span><div class="po-line-total-box"><strong class="js-receipt-line-total">{{ isset($line['line_total']) ? number_format((float) $line['line_total'], 2) : '0.00' }}</strong></div></div>
    <div class="po-cart-field po-cart-field--action"><span class="po-row-label" aria-hidden="true">Action</span><button type="button" class="btn w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-remove-receipt-line" title="Remove item"><iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></button></div>
  </div>
</div>
