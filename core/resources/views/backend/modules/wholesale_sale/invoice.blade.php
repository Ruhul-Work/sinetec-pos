@extends('backend.layouts.invoice')

@php
    $title = 'Wholesale Invoice ' . $sale->sale_no;
@endphp

@section('content')
    <style>
        @page {
            margin: 12mm;
        }

        .wholesale-invoice-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 16px;
        }

        .wholesale-invoice-actions a,
        .wholesale-invoice-actions button {
            padding: 7px 12px;
            border: 1px solid #94a3b8;
            border-radius: 6px;
            background: #fff;
            color: #0f172a;
            text-decoration: none;
        }

        .wholesale-invoice-title {
            margin: 0 0 18px;
            padding: 10px 0;
            border-top: 1px solid #334155;
            border-bottom: 1px solid #334155;
            text-align: center;
            font-size: 16px !important;
            line-height: 1.25;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .wholesale-invoice-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 28px;
            margin-bottom: 20px;
        }

        .wholesale-invoice-meta div {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            border-bottom: 1px dashed #cbd5e1;
            padding-bottom: 5px;
        }

        .wholesale-invoice-table {
            width: 100%;
            border-collapse: collapse;
        }

        .wholesale-invoice-table th,
        .wholesale-invoice-table td {
            padding: 8px;
            border: 1px solid #cbd5e1;
        }

        .wholesale-invoice-table th {
            background: #f1f5f9;
        }

        .wholesale-invoice-item-calculation {
            display: none;
        }

        .wholesale-invoice-totals {
            width: 340px;
            margin: 18px 0 0 auto;
        }

        .wholesale-invoice-totals div {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }

        .wholesale-invoice-totals .grand {
            margin-top: 4px;
            padding-top: 9px;
            border-top: 1px solid #334155;
            font-size: 16px;
            font-weight: 700;
        }

        .wholesale-invoice-note {
            margin-top: 24px;
            padding-top: 12px;
            border-top: 1px dashed #94a3b8;
        }

        @media print {
            .wholesale-invoice-actions {
                display: none !important;
            }
        }

        @media print and (max-width: 100mm) {
            @page {
                margin: 0;
            }

            html,
            body {
                width: 80mm !important;
                min-width: 80mm !important;
                height: auto !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            body {
                display: block !important;
                position: static !important;
                font-size: 11px;
            }

            .invoice-page {
                width: 74mm !important;
                max-width: 74mm !important;
                margin: 0 auto !important;
                padding: 0 !important;
                position: static !important;
                transform: none !important;
            }

            .invoice-branding {
                margin: 0 0 7px !important;
                padding: 0 !important;
                font-size: 11px;
            }

            .invoice-branding img {
                max-width: 42mm;
                max-height: 14mm;
            }

            .invoice-wrapper {
                margin: 0 !important;
                padding: 0 !important;
            }

            .wholesale-invoice-title {
                margin-bottom: 6px;
                padding: 5px 0;
                border-style: dashed;
                font-size: 12px !important;
                white-space: normal;
            }

            .wholesale-invoice-meta {
                margin-bottom: 7px;
                grid-template-columns: 1fr;
                gap: 2px;
            }

            .wholesale-invoice-meta div {
                padding-bottom: 2px;
                border-bottom: 0;
            }

            .wholesale-invoice-table {
                margin-bottom: 7px;
                table-layout: fixed;
            }

            .wholesale-invoice-table th,
            .wholesale-invoice-table td {
                padding: 3px 2px;
                border: 0;
                border-bottom: 1px dashed #b8b8b8;
                vertical-align: top;
            }

            .wholesale-invoice-table th {
                background: transparent;
            }

            .wholesale-col-index,
            .wholesale-col-unit,
            .wholesale-col-quantity,
            .wholesale-col-rate {
                display: none;
            }

            .wholesale-col-product {
                width: 70%;
            }

            .wholesale-col-total {
                width: 30%;
                text-align: right;
            }

            .wholesale-invoice-item-calculation {
                display: block;
                margin-top: 1px;
                color: #475569;
                font-size: 9px;
            }

            .wholesale-invoice-product-code {
                display: none;
            }

            .wholesale-invoice-totals {
                width: 100%;
                margin: 0;
                padding: 5px 0;
                border-bottom: 1px dashed #111;
            }

            .wholesale-invoice-totals div {
                padding: 2px 0;
            }

            .wholesale-invoice-totals .grand {
                padding-top: 4px;
                font-size: 13px;
            }

            .wholesale-invoice-note {
                margin-top: 8px;
                padding-top: 6px;
            }
        }
    </style>

    <div class="wholesale-invoice-actions no-print">
        <a href="{{ route('sales.wholesale.show', $sale) }}">Back to Invoice</a>
        <button type="button" onclick="window.print()">Print Again</button>
    </div>

    <div class="invoice-wrapper">
        <h1 class="wholesale-invoice-title">Wholesale Sales Invoice</h1>
        <div class="wholesale-invoice-meta">
            <div><span>Invoice No.</span><strong>{{ $sale->sale_no }}</strong></div>
            <div><span>Invoice Date</span><strong>{{ $sale->sale_at->format('d M Y') }}</strong></div>
            <div><span>Customer</span><strong>{{ $sale->customer->name }}</strong></div>
            <div><span>Due Date</span><strong>{{ $sale->due_date->format('d M Y') }}</strong></div>
            <div><span>Reference</span><strong>{{ $sale->customer_reference ?: '—' }}</strong></div>
            <div><span>Warehouse</span><strong>{{ $sale->warehouse->name }}</strong></div>
        </div>

        <table class="wholesale-invoice-table">
            <thead>
                <tr>
                    <th class="wholesale-col-index">#</th>
                    <th class="wholesale-col-product">Finished Product</th>
                    <th class="wholesale-col-unit">Unit</th>
                    <th class="text-end wholesale-col-quantity">Qty</th>
                    <th class="text-end wholesale-col-rate">Rate</th>
                    <th class="text-end wholesale-col-total">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sale->items as $item)
                    <tr>
                        <td class="wholesale-col-index">{{ $loop->iteration }}</td>
                        <td class="wholesale-col-product">
                            <strong>{{ $item->finishedProduct->name }}</strong><br>
                            <small class="wholesale-invoice-product-code">
                                {{ $item->finishedProduct->product_code }}
                            </small>
                            <span class="wholesale-invoice-item-calculation">
                                {{ rtrim(rtrim(number_format((float) $item->quantity, 6, '.', ''), '0'), '.') }}
                                {{ $item->unit->code }} × {{ number_format((float) $item->unit_price, 2) }}
                            </span>
                        </td>
                        <td class="wholesale-col-unit">{{ $item->unit->code }}</td>
                        <td class="text-end wholesale-col-quantity">
                            {{ rtrim(rtrim(number_format((float) $item->quantity, 6, '.', ''), '0'), '.') }}</td>
                        <td class="text-end wholesale-col-rate">{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td class="text-end wholesale-col-total">{{ number_format((float) $item->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="wholesale-invoice-totals">
            <div><span>Subtotal</span><span>{{ number_format((float) $sale->subtotal, 2) }}</span></div>
            <div><span>Discount</span><span>-{{ number_format((float) $sale->discount, 2) }}</span></div>
            <div><span>Tax</span><span>{{ number_format((float) $sale->tax, 2) }}</span></div>
            <div class="grand"><span>Invoice Total</span><span>{{ number_format((float) $sale->total, 2) }}</span></div>
            <div><span>Paid</span><span>{{ number_format((float) $sale->paid_amount, 2) }}</span></div>
            <div><strong>Balance Due</strong><strong>{{ number_format((float) $sale->due_amount, 2) }}</strong></div>
        </div>

        @if ($sale->note)
            <div class="wholesale-invoice-note"><strong>Note:</strong> {{ $sale->note }}</div>
        @endif
    </div>
@endsection
