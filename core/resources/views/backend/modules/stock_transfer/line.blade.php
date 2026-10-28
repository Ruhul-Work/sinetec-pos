@php
  $line = (array) $line;
  $value = fn (string $key, mixed $default = null) => $line[$key] ?? $default;
  $unitPrecision = max(0, min(6, (int) $value('unit_precision', 6)));
  $quantityStep = $unitPrecision === 0 ? '1' : '0.'.str_repeat('0', $unitPrecision - 1).'1';
@endphp
<tr>
  <td><select name="lines[{{ $index }}][domain]" class="form-control js-transfer-domain" required>@foreach ($domains as $domain => $config)<option value="{{ $domain }}" @selected($value('domain', 'raw_material') === $domain)>{{ $config['label'] }}</option>@endforeach</select></td>
  <td><select name="lines[{{ $index }}][item_id]" class="form-control js-transfer-item" placeholder="Select item" required>@if ($value('item_id'))<option value="{{ $value('item_id') }}" selected>{{ $value('item_text', 'Selected item') }}</option>@endif</select></td>
  <td><select name="lines[{{ $index }}][unit_id]" class="form-control js-transfer-unit" data-selected-precision="{{ $unitPrecision }}" placeholder="Select unit" required>@if ($value('unit_id'))<option value="{{ $value('unit_id') }}" selected>{{ $value('unit_text', 'Selected unit') }}</option>@endif</select></td>
  <td><input type="number" step="{{ $quantityStep }}" min="{{ $quantityStep }}" name="lines[{{ $index }}][quantity]" class="form-control js-transfer-quantity" value="{{ $value('quantity_input', $value('quantity')) }}" placeholder="0" required></td>
  <td><button type="button" class="w-36-px h-36-px rounded-circle border-0 d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-remove-transfer-line" title="Remove line"><iconify-icon icon="mdi:close"></iconify-icon></button></td>
</tr>
