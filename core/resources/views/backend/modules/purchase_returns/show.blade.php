@extends('backend.layouts.master')
@section('content')
    <div class="container">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <h5>Purchase Return Details:</h5>
            <div>
                <a href="{{ route('purchase.return.show', $order->id) }}"
                    class="btn btn-sm btn-outline-neutral-900"><iconify-icon icon="mdi:refresh" class="text-lg"></a>
                <a href="#" class="btn btn-sm btn-outline-primary-900" id="print-order"><iconify-icon icon="mdi:printer"
                        class="text-lg"></a>
                @if (($order->status ?? 'posted') !== 'cancelled')
                    <button type="button" class="btn btn-sm btn-outline-danger-900 btn-return-cancel"
                        data-url="{{ route('purchase.return.cancel', $order->id) }}">
                        <iconify-icon icon="mdi:cancel" class="text-lg"></iconify-icon>
                    </button>
                @endif
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <h6>Supplier Info</h6>
                <p><strong>Name: {{ $order->supplier->name }}</strong><br>Phone: {{ $order->supplier->phone ?? '' }}<br>Email: {{ $order->supplier->email ?? '' }}
                </p>
            </div>
            <div class="col-md-6 text-end">
                <h6>Return Order Info</h6>
                @php($statusValue = $order->status ?? 'posted')
                <p>Status: <span class="badge bg-{{ $statusValue === 'cancelled' ? 'danger' : 'success' }}">{{ ucfirst($statusValue) }}</span><br>

                    Order Date: {{ optional($order->created_at)->format('d M Y') }}<br>
                    Return No: {{ $order->return_number }}</p>

                @if ($order->purchase_invoice)
                    <p>Invoice: <a href="{{ asset($order->purchase_invoice) }}" target="_blank">View</a></p>
                    <img src="{{ asset($order->purchase_invoice) }}" alt="invoice" style="max-width:100px;">
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body p-0">
                <table class="table table-bordered p-1 mb-0">
                    <thead>
                        <tr>
                            <th>Return Item</th>
                            <th>SKU</th>
                            <th>Unit Cost</th>
                            <th>Qty</th>
                            <th>Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($order->items as $it)
                            <tr>
                                <td>{{ $it->product->name ?? '—' }}</td>
                                <td>{{ $it->sku }}</td>
                                <td>{{ number_format($it->unit_cost, 2) }}</td>
                                <td>{{ $it->quantity }}</td>
                                <td>{{ number_format($it->line_total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <h6>Payments</h6>
                <table class="table table-bordered  table-sm " id="payments-table">
                    <thead class="p-3 rounded-3">
                        <tr class="">
                            <th class="px-3 ">Date</th>
                            <th class="px-3">Method</th>
                            <th class="px-3">Amount</th>
                            <th class="px-3">Ref</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($order->purchaseReturnPayment as $p)
                            <tr>
                                <td class="px-3">{{ optional($p->created_at)->format('d-m-Y') }}</td>
                                <td class="px-3">{{ $p->method }}</td>
                                <td class="px-3">{{ number_format($p->amount, 2) }}</td>
                                <td class="px-3">{{ $p->reference }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="d-flex justify-content-between align-items-center">
                    @if (($order->status ?? 'posted') !== 'cancelled')
                        <button class="btn btn-sm btn-warning d-flex gap-1 AjaxModal" id="btn-add-payment"
                            data-onsuccess="purchasePayIndex.onSaved" data-order-id="{{ $order->id }}"
                            data-ajax-modal="{{ route('purchase.return.payment.modal', $order->id) }}">
                            <iconify-icon icon="material-symbols:currency-exchange-rounded" class="text-lg"></iconify-icon> Add
                            Payment
                        </button>
                    @else
                        <span class="badge bg-secondary">Cancelled</span>
                    @endif


                </div>
            </div>

            <div class="col-md-6 text-end lh-1">
                <h6>Summary</h6>
                <p class="">Subtotal: {{ number_format($order->subtotal, 2) }}</p>

                <p class="">Shipping: + {{ number_format($order->shipping_charge, 2) }}</p>
                <p class="">Discount: - {{ number_format($order->discount, 2) }}</p>
             
                <p class="fw-bold">Total: {{ number_format($order->total_amount, 2) }}</p>
                <p class="fw-bold">Paid: <span id="paid-amount">{{ number_format($order->paid_amount, 2) }}</span></p>
                <p class="fw-bold">Outstanding: <span
                        id="outstanding-amount">{{ number_format($order->due_amount, 2) }}</span>
                </p>


            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
        $(document).on('click', '.btn-return-cancel', function(e) {
            e.preventDefault();
            const url = $(this).data('url');

            const doCancel = function() {
                $.ajax({
                    url: url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(res) {
                        window.location.reload();
                        if (window.Swal) {
                            Swal.fire({
                                icon: 'success',
                                title: res?.message || 'Cancelled',
                                timer: 1200,
                                showConfirmButton: false
                            });
                        }
                    },
                    error: function(xhr) {
                        const msg = xhr.responseJSON?.message || xhr.responseJSON?.error ||
                            'Cancel failed';
                        Swal && Swal.fire({
                            icon: 'error',
                            title: 'Failed',
                            text: msg
                        });
                    }
                });
            };

            if (window.Swal) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Cancel purchase return?',
                    text: 'This will reverse stock and accounting entries.',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, cancel',
                    confirmButtonColor: '#d33'
                }).then(r => {
                    if (r.isConfirmed) doCancel();
                });
            } else if (confirm('Cancel this purchase return?')) {
                doCancel();
            }
        });

        window.purchasePayIndex = {
            onSaved: function() {
                $('#payments-table tbody').empty().append(
                    @foreach ($order->purchaseReturnPayment as $p)
                        `<tr>
                        <td>{{ optional($p->payment_date)->format('d-m-Y') }}</td>
                        <td>{{ $p->method }}</td>
                        <td>{{ number_format($p->amount, 2) }}</td>
                        <td>{{ $p->reference }}</td>
                    </tr>`,
                    @endforeach
                );



            }

        };

        window.purchaseReceipt = {
            onReceived: function(orderId) {

                //   window.location.href = "{{ route('purchase.index') }}"; 
                window.location.reload();
            }
        };
    </script>
@endsection
