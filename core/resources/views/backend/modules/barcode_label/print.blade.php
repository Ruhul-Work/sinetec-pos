<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EAN-13 Labels</title>
    <link rel="stylesheet" href="{{ asset('theme/admin/assets/css/lib/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('theme/admin/assets/css/custom.css') }}?v=20261119.1">
</head>
<body class="barcode-label-print">
    <div class="barcode-print-toolbar no-print">
        <div>
            <strong>A4 Barcode Preview</strong>
            <span>{{ $labelCount }} labels &middot; {{ $sheets->count() }} pages</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.close()">Close</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Print Labels</button>
        </div>
    </div>

    @foreach ($sheets as $sheet)
        <section class="barcode-label-sheet">
            @foreach ($sheet as $label)
                <article class="barcode-label-item">
                    <div class="barcode-label-product-name" title="{{ $label['name'] }}">{{ $label['name'] }}</div>
                    <div class="barcode-label-symbol">{!! $label['svg'] !!}</div>
                    <div class="barcode-label-number">{{ $label['barcode'] }}</div>
                </article>
            @endforeach
        </section>
    @endforeach
</body>
</html>
