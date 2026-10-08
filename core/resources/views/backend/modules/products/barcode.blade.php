@extends('backend.layouts.master')
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0 h6">Product Barcode</h5>
        </div>
        <div class="card-body">

            <form class="row g-2 align-items-end">

                <div class="col-md-6">
                    <label class="form-label">Select Product</label>
                    <select id="productFilter" class="form-control js-s2-ajax" data-url="{{ route('product.select2') }}"
                        data-placeholder="Search product (name, sku...)">
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Quantity</label>
                    <input type="number" id="barcodeQty" class="form-control" value="1" min="1" max="100">
                </div>

                <div class="col-md-3">
                    <button type="button" id="btnGenerateBarcode" class="btn btn-warning w-100">
                        Generate
                    </button>
                </div>

            </form>

            <hr class="my-3">

            <div id="barcodePreview" class="d-flex flex-wrap gap-3"></div>

            <div class="mt-3 row col-md-3 align-items-center">
                <button class="btn  btn-success no-print" onclick="printAllBarcodes()">
                    Print All
                </button>
            </div>
        </div>
    </div>
@endsection
@section('script')
    <script>
        $(document).ready(function() {
            window.S2.auto();
        });

        $(document).on('change', '#productFilter', function() {

            let productId = $(this).val();
            if (!productId) return;

            $('#barcodePreview').html('Loading...');

            $.get("{{ route('product.barcode.preview', ':id') }}".replace(':id', productId), function(res) {

                if (!res.success) {
                    $('#barcodePreview').html('<div class="text-danger">Barcode not found</div>');
                    return;
                }

                $('#barcodePreview').html(res.html);
            });
        });

        $(document).on('click', '#btnGenerateBarcode', function() {

            let productId = $('#productFilter').val();
            let qty = parseInt($('#barcodeQty').val());

            if (!productId || qty < 1) {
                Swal.fire('Error', 'Select product & quantity', 'error');
                return;
            }

            $('#barcodePreview').html('Loading...');

            $.get(
                "{{ route('product.barcode.preview', ':id') }}".replace(':id', productId), {
                    qty: qty
                },
                function(res) {

                    if (!res.success) {
                        $('#barcodePreview').html('<div class="text-danger">Barcode not found</div>');
                        return;
                    }

                    $('#barcodePreview').html(res.html);
                }
            );
        });
    </script>
@endsection
