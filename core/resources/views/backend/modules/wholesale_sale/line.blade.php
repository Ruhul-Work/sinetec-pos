@php
  $line = is_array($line ?? null) ? $line : [];
  $productId = $line['finished_product_id'] ?? null;
@endphp

<div class="wholesale-line" data-line-index="{{ $index }}">
  <div class="wholesale-line__product">
    <label class="form-label d-md-none">Finished Product</label>
    <select name="items[{{ $index }}][finished_product_id]"
            class="form-control form-control-sm js-wholesale-product" required>
      @if ($productId)
        <option value="{{ $productId }}" selected>{{ $line['item_text'] ?? ('Product #'.$productId) }}</option>
      @endif
    </select>
  </div>

  <div class="wholesale-line__stock">
    <span class="d-md-none text-muted text-xs">Available Stock</span>
    <strong class="js-wholesale-stock">{{ $line['stock_quantity'] ?? '—' }}</strong>
    <small class="js-wholesale-stock-unit">{{ $line['stock_unit_code'] ?? '' }}</small>
  </div>

  <div>
    <label class="form-label d-md-none">Selling Unit</label>
    <select name="items[{{ $index }}][unit_id]"
            class="form-control form-control-sm js-wholesale-unit" required>
      @if ($line['unit_id'] ?? null)
        <option value="{{ $line['unit_id'] }}" selected>{{ $line['unit_text'] ?? ('Unit #'.$line['unit_id']) }}</option>
      @endif
    </select>
  </div>

  <div>
    <label class="form-label d-md-none">Quantity</label>
    <div class="wholesale-quantity-control">
      <button type="button" class="js-wholesale-quantity-step" data-direction="-1"
              title="Decrease quantity" aria-label="Decrease quantity">
        <iconify-icon icon="mdi:minus"></iconify-icon>
      </button>
      <input type="number" name="items[{{ $index }}][quantity]"
             class="form-control form-control-sm text-center js-wholesale-quantity"
             min="0.000001" step="0.000001" value="{{ $line['quantity'] ?? '1' }}" required>
      <button type="button" class="js-wholesale-quantity-step" data-direction="1"
              title="Increase quantity" aria-label="Increase quantity">
        <iconify-icon icon="mdi:plus"></iconify-icon>
      </button>
    </div>
  </div>

  <div>
    <label class="form-label d-md-none">Rate / Unit</label>
    <input type="number" name="items[{{ $index }}][unit_price]"
           class="form-control form-control-sm text-end js-wholesale-price"
           min="0.01" step="0.01" value="{{ $line['unit_price'] ?? '' }}" placeholder="0.00" required>
  </div>

  <div class="wholesale-line__total">
    <span class="d-md-none text-muted text-xs">Line Total</span>
    <strong class="js-wholesale-line-total">{{ $line['line_total'] ?? '0.00' }}</strong>
  </div>

  <button type="button"
          class="btn w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center
                 bg-danger-focus text-danger-main js-remove-wholesale-line"
          title="Remove item" aria-label="Remove item">
    <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
  </button>
</div>
