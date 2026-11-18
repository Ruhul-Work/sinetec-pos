@php
  $line = is_array($line ?? null) ? $line : [];
  $productId = $line['finished_product_id'] ?? null;
  $productName = $line['product_name'] ?? 'Finished Product';
  $productCode = $line['product_code'] ?? '';
  $unitId = $line['unit_id'] ?? null;
  $stockQuantity = $line['stock_quantity'] ?? null;
  $stockUnitCode = $line['stock_unit_code'] ?? '';
@endphp

<div class="pos-cart-line" data-product-id="{{ $productId }}">
  <input type="hidden" name="items[{{ $index }}][finished_product_id]" class="js-pos-product-id" value="{{ $productId }}">
  <input type="hidden" name="items[{{ $index }}][product_name]" class="js-pos-product-name" value="{{ $productName }}">
  <input type="hidden" name="items[{{ $index }}][product_code]" class="js-pos-product-code" value="{{ $productCode }}">

  <div class="pos-cart-line__row">
    <div class="pos-cart-line__identity">
      <strong class="pos-cart-line__name" title="{{ $productName }}">{{ $productName }}</strong>
    </div>

    <div class="pos-cart-line__stock {{ $stockQuantity === null ? 'd-none' : '' }}">
      <span class="pos-cart-mobile-label">Stock</span>
      <strong>
        <span class="js-pos-stock-quantity">{{ $stockQuantity }}</span>
        <span class="js-pos-stock-unit">{{ $stockUnitCode }}</span>
      </strong>
    </div>

    <div class="pos-cart-field pos-cart-field--unit">
      <label class="po-row-label">Unit <span class="text-danger">*</span></label>
      <select name="items[{{ $index }}][unit_id]" class="form-control js-pos-unit"
              data-selected-precision="{{ $line['unit_precision'] ?? 6 }}" required>
        @if ($unitId)
          <option value="{{ $unitId }}" selected>{{ $line['unit_text'] ?? ('Unit #'.$unitId) }}</option>
        @endif
      </select>
    </div>

    <div class="pos-cart-field pos-cart-field--quantity">
      <label class="po-row-label">Quantity <span class="text-danger">*</span></label>
      <div class="po-quantity-control">
        <button type="button" class="po-quantity-step js-pos-quantity-step" data-direction="-1" title="Decrease quantity">
          <iconify-icon icon="mdi:minus"></iconify-icon>
        </button>
        <input type="number" name="items[{{ $index }}][quantity]"
               class="form-control text-center js-pos-quantity"
               min="0.000001" step="0.000001" value="{{ $line['quantity'] ?? 1 }}" required>
        <button type="button" class="po-quantity-step js-pos-quantity-step" data-direction="1" title="Increase quantity">
          <iconify-icon icon="mdi:plus"></iconify-icon>
        </button>
      </div>
    </div>

    <div class="pos-cart-field">
      <label class="po-row-label">Price <span class="text-danger">*</span></label>
      <input type="number" name="items[{{ $index }}][unit_price]"
             class="form-control text-end js-pos-price"
             min="0.01" step="0.01" value="{{ $line['unit_price'] ?? '' }}"
             placeholder="0.00" required>
    </div>

    <div class="pos-cart-field pos-cart-field--total">
      <span class="po-row-label">Total</span>
      <div class="po-line-total-box">
        <strong class="js-pos-line-total">{{ $line['line_total'] ?? '0.00' }}</strong>
      </div>
    </div>

    <div class="pos-cart-line__action">
      <button type="button"
              class="btn w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center
                     bg-danger-focus text-danger-main btn-remove-pos-line"
              title="Remove item"
              aria-label="Remove {{ $productName }}">
        <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
      </button>
    </div>
  </div>
</div>
