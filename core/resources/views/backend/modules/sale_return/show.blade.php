@extends('backend.layouts.master')

@php
    $isDraft = $saleReturn->status === \App\Enums\Sales\SaleReturnStatus::Draft;
    $sourceRoute = $saleReturn->sale->channel === \App\Enums\Sales\SaleChannel::Pos
        ? route('sales.pos.show', $saleReturn->sale)
        : route('sales.wholesale.show', $saleReturn->sale);
@endphp

@section('meta')
    <title>{{ $saleReturn->return_no }}</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h6 class="fw-semibold mb-0">Sales Return {{ $saleReturn->return_no }}</h6>
                <span class="badge text-sm fw-semibold {{ $isDraft ? 'bg-dark-warning-gradient' : 'bg-dark-success-gradient' }} px-12 py-7 radius-4 text-white">
                    {{ $saleReturn->status->label() }}
                </span>
            </div>
            <p class="text-muted mb-0">
                {{ $isDraft ? 'Draft review — no stock or Finance impact yet' : 'Posted return — stock, customer balance and Finance updated' }}
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('sales.returns.index') }}"
                class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button">
                <iconify-icon icon="mdi:arrow-left"></iconify-icon>
                <span>Back</span>
            </a>
            @if ($isDraft)
                @perm('sales.returns.update', 'edit')
                    <a href="{{ route('sales.returns.edit', $saleReturn) }}"
                        class="btn btn-outline-primary btn-sm px-14 py-8 radius-8 app-action-button">
                        <iconify-icon icon="mdi:pencil-outline"></iconify-icon>
                        <span>Edit Draft</span>
                    </a>
                @endperm
                @perm('sales.returns.post', 'add')
                    <button type="button"
                        class="btn btn-success btn-sm px-14 py-8 radius-8 app-action-button AjaxModal"
                        data-ajax-modal="{{ route('sales.returns.post-modal', $saleReturn) }}"
                        data-size="xl" data-onsuccess="SaleReturnDetails.onPosted">
                        <iconify-icon icon="mdi:check-decagram-outline"></iconify-icon>
                        <span>Review & Post</span>
                    </button>
                @endperm
            @endif
        </div>
    </div>

    <div class="card mb-24">
        <div class="card-header">
            <h5 class="card-title mb-0">Return Information</h5>
        </div>
        <div class="card-body">
            <div class="row gy-3">
                <div class="col-md-3">
                    <small class="text-muted d-block">Source Sale</small>
                    <a href="{{ $sourceRoute }}" class="fw-semibold">{{ $saleReturn->sale->sale_no }}</a>
                    <span class="badge bg-primary-focus text-primary-main radius-4 ms-1">
                        {{ $saleReturn->sale->channel->label() }}
                    </span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Customer</small>
                    <strong>{{ $saleReturn->sale->customer?->name ?: 'Walk-in Customer' }}</strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Warehouse</small>
                    <strong>{{ $saleReturn->warehouse->name }}</strong>
                    <span class="text-muted">({{ $saleReturn->warehouse->code }})</span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Return Date & Time</small>
                    <strong>{{ $saleReturn->return_at->format('d M Y, h:i A') }}</strong>
                </div>
                <div class="col-12">
                    <small class="text-muted d-block">Reason</small>
                    <span>{{ $saleReturn->reason }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Returned Lines</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table bordered-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Finished Product</th>
                            <th>Sale Unit</th>
                            <th class="text-end">Return Quantity</th>
                            <th class="text-end">Base Quantity</th>
                            <th class="text-end">Original Rate</th>
                            <th class="text-end">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($saleReturn->items as $index => $item)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>
                                    <strong class="d-block">{{ $item->finishedProduct->name }}</strong>
                                    <code>{{ $item->finishedProduct->product_code }}</code>
                                </td>
                                <td>{{ $item->unit->name }} ({{ $item->unit->code }})</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format((float) $item->quantity, 6, '.', ''), '0'), '.') }}</td>
                                <td class="text-end">
                                    {{ rtrim(rtrim(number_format((float) $item->base_quantity, 6, '.', ''), '0'), '.') }}
                                    {{ $item->finishedProduct->baseUnit->code }}
                                </td>
                                <td class="text-end">{{ number_format((float) $item->unit_price, 2) }}</td>
                                <td class="text-end fw-semibold">{{ number_format((float) $item->line_total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="row justify-content-end mt-4">
                <div class="col-xl-4 col-lg-5">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>Return Subtotal</span>
                        <strong>{{ number_format((float) $saleReturn->subtotal, 2) }}</strong>
                    </div>
                    @if ((float) $saleReturn->discount > 0)
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span>Discount Reversal</span>
                            <strong>-{{ number_format((float) $saleReturn->discount, 2) }}</strong>
                        </div>
                    @endif
                    @if ((float) $saleReturn->tax > 0)
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span>Tax Reversal</span>
                            <strong>+{{ number_format((float) $saleReturn->tax, 2) }}</strong>
                        </div>
                    @endif
                    <div class="d-flex justify-content-between align-items-center border-top pt-3">
                        <strong>Return Total</strong>
                        <strong class="text-danger-main fs-6">{{ number_format((float) $saleReturn->total, 2) }}</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if (! $isDraft)
        <div class="card mt-24">
            <div class="card-header">
                <h5 class="card-title mb-0">Customer Balance Settlement</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="border radius-8 p-12 h-100 bg-warning-50">
                            <small class="text-muted d-block">Due Adjusted</small>
                            <strong>{{ number_format((float) $saleReturn->due_adjustment, 2) }}</strong>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border radius-8 p-12 h-100 bg-success-50">
                            <small class="text-muted d-block">Refund Paid</small>
                            <strong>{{ number_format((float) $saleReturn->refund_amount, 2) }}</strong>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border radius-8 p-12 h-100">
                            <small class="text-muted d-block">Posted At</small>
                            <strong>{{ $saleReturn->posted_at?->format('d M Y, h:i A') }}</strong>
                        </div>
                    </div>
                </div>

                @if ($saleReturn->payments->isNotEmpty())
                    <div class="table-responsive mt-20">
                        <table class="table bordered-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Refund Method</th>
                                    <th>Paid From Account</th>
                                    <th>Reference</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($saleReturn->payments as $payment)
                                    <tr>
                                        <td>{{ $payment->paymentType->name }}</td>
                                        <td>{{ $payment->account->code }} — {{ $payment->account->name }}</td>
                                        <td>{{ $payment->reference_no ?: '—' }}</td>
                                        <td class="text-end fw-semibold">
                                            {{ number_format((float) $payment->amount, 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
@endsection

@section('script')
    <script>
        window.SaleReturnDetails = {
            onPosted: function(response) {
                window.location.href = response?.redirect_url || window.location.href;
            }
        };
    </script>
@endsection
