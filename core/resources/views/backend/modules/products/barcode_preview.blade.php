{{-- <div class="border p-3 text-center barcode-label" id="printArea" style="width:410px">
    <div class="fw-bold product-name">{{ $product->name }}</div>

    <div class="my-1">
        <img src="data:image/png;base64,{{ DNS1D::getBarcodePNG($product->barcode, 'C128', 2, 60) }}"
            style="width:100%; height:auto;">
    </div>

    <div class="barcode-text text-center">{{ $product->barcode }}</div>
    <div class="price text-center">৳ {{ number_format($product->price, 2) }}</div>

    <button class="btn btn-sm btn-success mt-2 no-print" onclick="printBarcode()">
        Print
    </button>
</div> --}}

<div class="barcode-grid">
@for ($i = 0; $i < $qty; $i++)
    <div class="barcode-label">

        <div class="product-name">
            {{ $product->name }}
        </div>

        <img src="data:image/png;base64,{{ DNS1D::getBarcodePNG($barcodeValue, 'C128', 2, 60) }}" class="barcode-img">

        <div class="barcode-text">
            {{ $barcodeValue }}
        </div>

        <div class="price">
            ৳ {{ number_format($product->price, 2) }}
        </div>

    </div>
@endfor
</div>

<style>
    /* for  pos printer */
    /* .barcode-label {
        width: 50mm;
        padding: 3mm;
        text-align: center;
        border: 1px dashed #ccc;
        font-family: Arial, sans-serif;
    }

    .product-name {
        font-size: 11px;
        font-weight: bold;
        margin-bottom: 2mm;
    }

    .barcode-img {
        width: 100%;
        height: auto;
    }

    .barcode-text {
        font-size: 10px;
        letter-spacing: 1px;
    }

    .price {
        font-size: 12px;
        font-weight: bold;
        margin-top: 1mm;
    }

    @media print {
        .no-print {
            display: none !important;
        }

        body {
            margin: 0;
        }

        .barcode-label {
            page-break-inside: avoid;
        }
    } */

    /* grid for A4 print */
    .barcode-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
    }

    .barcode-label {
        width: 100%;
        border: 1px dashed #ccc;
        padding: 6px;
        text-align: center;
        font-family: Arial, sans-serif;
    }

    .product-name {
        font-size: 11px;
        font-weight: bold;
        margin-bottom: 2mm;
    }

    .barcode-img {
        width: 100%;
        height: auto;
    }

    .barcode-text {
        font-size: 10px;
        letter-spacing: 1px;
    }

    .price {
        font-size: 12px;
        font-weight: bold;
        margin-top: 1mm;
    }

    

    @media print {


        body {
            margin: 10mm;
        }

        .barcode-label {
            page-break-inside: avoid;
        }
    }
</style>

<script>
   
    function printAllBarcodes() {

        let content = document.getElementById('barcodePreview').innerHTML;

        let win = window.open('', '', 'width=800,height=600');

        win.document.write(`
        <html>
        <head>
            <style>
                body { font-family: Arial; }
                .no-print {
                        display: none !important;
                    }
                    
            </style>
        </head>
        <body onload="window.print(); window.close();">
            ${content}
        </body>
        </html>
    `);

        win.document.close();
    }
</script>
