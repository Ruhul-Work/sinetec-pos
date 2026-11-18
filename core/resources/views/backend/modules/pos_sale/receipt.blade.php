@extends('backend.layouts.invoice')

@php
  $title = 'Receipt '.$sale->sale_no;
  $totalChange = $sale->payments->sum(fn ($payment) => (float) $payment->change_amount);
@endphp

@section('content')
  <style>
    @page {
      margin: 0;
    }

    .invoice-page {
      width: 74mm;
      max-width: 74mm;
      padding: 0;
      font-family: Arial, sans-serif;
      font-size: 11px;
      color: #111;
    }

    .invoice-branding {
      margin-bottom: 7px;
      padding: 0;
      font-size: 11px;
    }

    .invoice-branding img {
      max-width: 42mm;
      max-height: 14mm;
    }

    .thermal-actions {
      margin-bottom: 8px;
      display: flex;
      justify-content: center;
      gap: 6px;
    }

    .thermal-actions a,
    .thermal-actions button {
      padding: 5px 9px;
      border: 1px solid #94a3b8;
      border-radius: 5px;
      color: #0f172a;
      background: #fff;
      font-size: 11px;
      text-decoration: none;
    }

    .receipt-title {
      margin: 0 0 5px;
      padding: 5px 0;
      border-top: 1px dashed #111;
      border-bottom: 1px dashed #111;
      text-align: center;
      font-size: 12px !important;
      font-weight: 600;
      text-transform: uppercase;
    }

    .receipt-meta {
      margin-bottom: 6px;
      display: grid;
      gap: 2px;
    }

    .receipt-meta-row,
    .receipt-total-row,
    .receipt-payment-row {
      display: flex;
      justify-content: space-between;
      gap: 8px;
    }

    .receipt-meta-row span:last-child,
    .receipt-total-row span:last-child,
    .receipt-payment-row span:last-child {
      text-align: right;
    }

    .receipt-items {
      width: 100%;
      margin-bottom: 6px;
      border-collapse: collapse;
    }

    .receipt-items th,
    .receipt-items td {
      padding: 3px 2px;
      border-bottom: 1px dashed #b8b8b8;
      vertical-align: top;
    }

    .receipt-items th:first-child,
    .receipt-items td:first-child {
      padding-left: 0;
    }

    .receipt-items th:last-child,
    .receipt-items td:last-child {
      padding-right: 0;
      text-align: right;
    }

    .receipt-item-name {
      display: block;
      font-weight: 700;
    }

    .receipt-item-calculation {
      display: block;
      color: #475569;
      font-size: 9px;
    }

    .receipt-totals,
    .receipt-payments {
      padding: 5px 0;
      display: grid;
      gap: 3px;
      border-bottom: 1px dashed #111;
    }

    .receipt-total-row--grand {
      padding-top: 4px;
      border-top: 1px solid #111;
      font-size: 13px;
      font-weight: 700;
    }

    .receipt-footer-note {
      padding-top: 8px;
      text-align: center;
      font-size: 10px;
    }

    @media print {
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
      }

      .invoice-page {
        width: 74mm !important;
        max-width: 74mm !important;
        margin: 0 auto !important;
        padding: 0 !important;
        position: static !important;
        transform: none !important;
      }

      .invoice-branding,
      .invoice-wrapper {
        margin-top: 0 !important;
        padding-top: 0 !important;
      }

      .thermal-actions {
        display: none !important;
      }
    }
  </style>

  <div class="thermal-actions no-print">
    <a href="{{ route('sales.pos.show', $sale) }}">Back to Sale</a>
    <button type="button" onclick="window.print()">Print Again</button>
  </div>

  <div class="invoice-wrapper p-0">
    <h1 class="receipt-title">Sales Receipt</h1>

    <div class="receipt-meta">
      <div class="receipt-meta-row"><span>Sale No.</span><strong>{{ $sale->sale_no }}</strong></div>
      <div class="receipt-meta-row"><span>Date</span><span>{{ $sale->sale_at->format('d M Y, h:i A') }}</span></div>
      <div class="receipt-meta-row"><span>Customer</span><span>{{ $sale->customer?->name ?? 'Walk-in Customer' }}</span></div>
      <div class="receipt-meta-row"><span>Counter</span><span>{{ $sale->warehouse->name }}</span></div>
    </div>

    <table class="receipt-items">
      <thead>
        <tr>
          <th>Item</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($sale->items as $item)
          <tr>
            <td>
              <span class="receipt-item-name">{{ $item->finishedProduct->name }}</span>
              <span class="receipt-item-calculation">
                {{ rtrim(rtrim(number_format((float) $item->quantity, 6, '.', ''), '0'), '.') }}
                {{ $item->unit->code }} × {{ number_format((float) $item->unit_price, 2) }}
              </span>
            </td>
            <td>{{ number_format((float) $item->line_total, 2) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <div class="receipt-totals">
      <div class="receipt-total-row"><span>Subtotal</span><span>{{ number_format((float) $sale->subtotal, 2) }}</span></div>
      @if ((float) $sale->discount > 0)
        <div class="receipt-total-row"><span>Discount</span><span>-{{ number_format((float) $sale->discount, 2) }}</span></div>
      @endif
      @if ((float) $sale->tax > 0)
        <div class="receipt-total-row"><span>Tax</span><span>{{ number_format((float) $sale->tax, 2) }}</span></div>
      @endif
      <div class="receipt-total-row receipt-total-row--grand">
        <span>Total</span><span>{{ number_format((float) $sale->total, 2) }}</span>
      </div>
    </div>

    <div class="receipt-payments">
      @foreach ($sale->payments as $payment)
        <div class="receipt-payment-row">
          <span>{{ $payment->paymentType->name }}</span>
          <span>{{ number_format((float) $payment->amount, 2) }}</span>
        </div>
      @endforeach
      <div class="receipt-payment-row"><strong>Paid</strong><strong>{{ number_format((float) $sale->paid_amount, 2) }}</strong></div>
      @if ((float) $sale->due_amount > 0)
        <div class="receipt-payment-row"><strong>Due</strong><strong>{{ number_format((float) $sale->due_amount, 2) }}</strong></div>
      @endif
      @if ($totalChange > 0)
        <div class="receipt-payment-row"><strong>Change</strong><strong>{{ number_format($totalChange, 2) }}</strong></div>
      @endif
    </div>

    <div class="receipt-footer-note">
      Thank you for your purchase.<br>
      Served by {{ $sale->postedBy?->name ?? $sale->createdBy?->name ?? 'Operator' }}
    </div>
  </div>
@endsection
