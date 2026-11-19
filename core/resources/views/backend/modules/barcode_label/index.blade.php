@extends('backend.layouts.master')

@section('meta')
    <title>Barcode / Labels</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <h6 class="fw-semibold mb-0">Barcode / Labels</h6>
            <p class="text-muted m-0">Prepare internal EAN-13 labels for Finished Products</p>
        </div>
        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
                <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Dashboard
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Barcode / Labels</li>
        </ul>
    </div>

    <form method="POST" action="{{ route('inventory.barcode-labels.preview') }}" target="_blank" id="barcodeLabelForm">
        @csrf

        <div class="card barcode-label-builder-card">
            <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div class="barcode-label-builder-heading">
                    <div class="d-flex align-items-center gap-1">
                        <h5 class="card-title mb-1">A4 Label Builder</h5>
                        <x-backend.page-note
                            title="Internal EAN-13 rule"
                            label="View barcode rule"
                            align="start"
                            class="barcode-label-builder-note"
                        >
                            A missing barcode is assigned once and saved to the Finished Product. Reprinting always uses the same EAN-13 number.
                        </x-backend.page-note>
                    </div>
                    <p class="text-muted text-sm mb-0">Select products and enter how many labels each product needs.</p>
                </div>

                <button type="button" class="btn btn-outline-primary btn-sm app-action-button" id="addBarcodeProduct">
                    <iconify-icon icon="mdi:plus"></iconify-icon>
                    <span>Add Product</span>
                </button>
            </div>

            <div class="card-body">
                @if ($errors->any())
                    <div class="alert alert-danger d-flex align-items-start gap-2">
                        <iconify-icon icon="mdi:alert-circle-outline" class="text-xl mt-1"></iconify-icon>
                        <div>{{ $errors->first() }}</div>
                    </div>
                @endif

                <div class="barcode-label-line-header" aria-hidden="true">
                    <span>Finished Product</span>
                    <span>Current Barcode</span>
                    <span class="text-center">Label Quantity</span>
                    <span></span>
                </div>

                <div id="barcodeProductLines" class="barcode-label-lines"></div>

                <div class="barcode-label-job-summary">
                    <div>
                        <span>Selected Products</span>
                        <strong id="selectedProductCount">0</strong>
                    </div>
                    <div>
                        <span>Total Labels</span>
                        <strong id="totalLabelCount">0</strong>
                    </div>
                    <small>Maximum 400 labels in one print job.</small>
                </div>
            </div>

            <div class="card-footer d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-outline-danger app-action-button" id="clearBarcodeProducts">
                    <iconify-icon icon="mdi:delete-sweep-outline"></iconify-icon>
                    <span>Clear All</span>
                </button>
                <button type="submit" class="btn app-primary-action app-action-button px-24">
                    <iconify-icon icon="mdi:file-eye-outline"></iconify-icon>
                    <span>Generate A4 Preview</span>
                </button>
            </div>
        </div>
    </form>

    <template id="barcodeProductLineTemplate">
        <div class="barcode-label-line">
            <div>
                <select class="form-control form-control-sm js-barcode-product" required>
                    <option value="">Search Finished Product</option>
                </select>
            </div>
            <div class="barcode-label-current-number">
                <span class="barcode-status-badge is-pending">Assigned on preview</span>
            </div>
            <div>
                <input type="number" class="form-control form-control-sm text-center js-label-quantity" value="1" min="1" max="400" required>
            </div>
            <div class="text-center">
                <button type="button" class="barcode-label-remove" title="Remove product">
                    <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
                </button>
            </div>
        </div>
    </template>
@endsection

@section('script')
    <script>
        $(function() {
            const productUrl = @json(route('inventory.barcode-labels.products'));
            const $lines = $('#barcodeProductLines');

            function refreshIndexes() {
                $lines.find('.barcode-label-line').each(function(index) {
                    $(this).find('.js-barcode-product').attr('name', `products[${index}][finished_product_id]`);
                    $(this).find('.js-label-quantity').attr('name', `products[${index}][quantity]`);
                });

                const selectedProducts = $lines.find('.js-barcode-product').filter(function() {
                    return Boolean($(this).val());
                }).length;
                let totalLabels = 0;

                $lines.find('.js-label-quantity').each(function() {
                    totalLabels += Math.max(Number.parseInt($(this).val(), 10) || 0, 0);
                });

                $('#selectedProductCount').text(selectedProducts);
                $('#totalLabelCount').text(totalLabels);
            }

            function initialiseProductSelect($select) {
                $select.select2({
                    width: '100%',
                    placeholder: 'Search by name, code or barcode',
                    allowClear: true,
                    ajax: {
                        url: productUrl,
                        dataType: 'json',
                        delay: 250,
                        data: params => ({q: params.term || ''}),
                        processResults: response => response,
                        cache: true
                    }
                });
            }

            function addLine() {
                const line = $($('#barcodeProductLineTemplate').html());
                $lines.append(line);
                initialiseProductSelect(line.find('.js-barcode-product'));
                refreshIndexes();
            }

            $('#addBarcodeProduct').on('click', addLine);

            $('#clearBarcodeProducts').on('click', function() {
                $lines.empty();
                addLine();
            });

            $lines.on('click', '.barcode-label-remove', function() {
                $(this).closest('.barcode-label-line').remove();

                if (! $lines.children().length) {
                    addLine();
                    return;
                }

                refreshIndexes();
            });

            $lines.on('select2:select', '.js-barcode-product', function(event) {
                const selectedId = String(event.params.data.id);
                let duplicate = false;

                $lines.find('.js-barcode-product').not(this).each(function() {
                    if (String($(this).val() || '') === selectedId) {
                        duplicate = true;
                    }
                });

                if (duplicate) {
                    $(this).val(null).trigger('change.select2');
                    Swal.fire({
                        icon: 'warning',
                        title: 'Product already selected',
                        text: 'Increase the existing row quantity instead of adding the product again.'
                    });
                    return;
                }

                const barcode = event.params.data.barcode;
                const $badge = $(this).closest('.barcode-label-line').find('.barcode-status-badge');
                $badge
                    .toggleClass('is-pending', ! barcode)
                    .toggleClass('is-assigned', Boolean(barcode))
                    .text(barcode || 'Assigned on preview');
                refreshIndexes();
            });

            $lines.on('select2:clear change input', '.js-barcode-product, .js-label-quantity', refreshIndexes);

            $('#barcodeLabelForm').on('submit', function(event) {
                const selectedProducts = $lines.find('.js-barcode-product').filter(function() {
                    return Boolean($(this).val());
                }).length;
                const totalLabels = Number.parseInt($('#totalLabelCount').text(), 10) || 0;

                if (! selectedProducts) {
                    event.preventDefault();
                    Swal.fire({icon: 'warning', title: 'Add a Finished Product first'});
                    return;
                }

                if (totalLabels > 400) {
                    event.preventDefault();
                    Swal.fire({icon: 'warning', title: 'Too many labels', text: 'A single print job may contain up to 400 labels.'});
                }
            });

            addLine();
        });
    </script>
@endsection
