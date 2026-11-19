@extends('backend.layouts.invoice')

@php
  $title = 'Customer Receipt '.$receipt->receipt_no;
  $totalTendered = $receipt->payments->sum(fn ($payment) => (float) $payment->tendered_amount);
  $totalChange = $receipt->payments->sum(fn ($payment) => (float) $payment->change_amount);
@endphp

@section('content')
  <style>
    @page {
      size: 80mm auto;
      margin: 3mm;
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

    .receipt-actions {
      margin-bottom: 8px;
      display: flex;
      justify-content: center;
      gap: 6px;
    }

    .receipt-actions a,
    .receipt-actions button {
      padding: 5px 9px;
      border: 1px solid #94a3b8;
      border-radius: 5px;
      color: #0f172a;
      background: #fff;
      font-size: 11px;
      text-decoration: none;
    }

    .customer-receipt-title {
      margin: 0 0 6px;
      padding: 5px 0;
      border-top: 1px dashed #111;
      border-bottom: 1px dashed #111;
      text-align: center;
      font-size: 12px !important;
      font-weight: 700;
      text-transform: uppercase;
    }

    .receipt-row {
      display: flex;
      justify-content: space-between;
      gap: 8px;
      margin-bottom: 2px;
    }

    .receipt-row span:last-child,
    .receipt-row strong:last-child {
      text-align: right;
    }

    .receipt-section {
      margin-top: 7px;
      padding-top: 6px;
      border-top: 1px dashed #111;
    }

    .receipt-section-title {
      margin-bottom: 4px;
      font-weight: 700;
    }

    .receipt-total {
      padding-top: 4px;
      border-top: 1px solid #111;
      font-size: 12px;
      font-weight: 700;
    }

    .receipt-note {
      margin-top: 7px;
      padding-top: 6px;
      border-top: 1px dashed #111;
      text-align: center;
      color: #475569;
    }

    @media print {
      .receipt-actions {
        display: none !important;
      }
    }
  </style>

  <div class="receipt-actions no-print">
    <a href="{{ $saleDetailsUrl }}">Back to Sale</a>
    <button type="button" onclick="window.print()">Print Again</button>
  </div>

  <div class="invoice-wrapper p-0">
    <h1 class="customer-receipt-title">Customer Payment Receipt</h1>

    <div class="receipt-row"><span>Receipt No.</span><strong>{{ $receipt->receipt_no }}</strong></div>
    <div class="receipt-row"><span>Collection Date</span><span>{{ $receipt->receipt_at->format('d M Y, h:i A') }}</span></div>
    <div class="receipt-row"><span>Sale No.</span><strong>{{ $receipt->sale->sale_no }}</strong></div>
    <div class="receipt-row"><span>Customer</span><span>{{ $receipt->customer->name }}</span></div>
    <div class="receipt-row"><span>Branch</span><span>{{ $receipt->branch->name }}</span></div>

    <div class="receipt-section">
      <div class="receipt-section-title">Payment Breakdown</div>
      @foreach ($receipt->payments as $payment)
        <div class="receipt-row">
          <span>{{ $payment->paymentType->name }} · {{ $payment->account->name }}</span>
          <strong>{{ number_format((float) $payment->amount, 2) }}</strong>
        </div>
        @if ($payment->reference_no)
          <div class="receipt-row"><small>Reference</small><small>{{ $payment->reference_no }}</small></div>
        @endif
      @endforeach
    </div>

    <div class="receipt-section">
      <div class="receipt-row"><span>Due Before</span><span>{{ number_format((float) $receipt->due_before, 2) }}</span></div>
      <div class="receipt-row receipt-total"><span>Collected</span><span>{{ number_format((float) $receipt->amount, 2) }}</span></div>
      <div class="receipt-row"><span>Due After</span><strong>{{ number_format((float) $receipt->due_after, 2) }}</strong></div>
      @if ($totalTendered > (float) $receipt->amount)
        <div class="receipt-row"><span>Tendered</span><span>{{ number_format($totalTendered, 2) }}</span></div>
        <div class="receipt-row"><span>Change Returned</span><strong>{{ number_format($totalChange, 2) }}</strong></div>
      @endif
    </div>

    @if ($receipt->note)
      <div class="receipt-note">{{ $receipt->note }}</div>
    @endif
    <div class="receipt-note">
      Received by {{ $receipt->postedBy?->name ?? 'System' }}<br>
      Thank you
    </div>
  </div>
@endsection
