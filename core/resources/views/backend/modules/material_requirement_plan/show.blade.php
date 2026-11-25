@extends('backend.layouts.master')

@section('meta')
    <title>Material Requirement Plan {{ $plan->plan_no }}</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h6 class="fw-semibold mb-0">MRP {{ $plan->plan_no }}</h6>
                <span class="badge text-sm fw-semibold px-12 py-7 radius-4 {{ $plan->status->badgeClass() }}">
                    {{ $plan->status->label() }}
                </span>
                <x-backend.page-note title="MRP Calculation Logic" label="View MRP details">
                    Net Finished Product requirement is calculated as: <code>Demand + Safety &minus; Available Stock</code>.
                    The BOM valid on the target month's first day is used. Component need is calculated as:
                    <code>Gross BOM Need + Minimum Stock Buffer &minus; On-Hand Stock &minus; Incoming Approved/Partial PO</code>.
                    <code>BOTH</code> Parts default to Buy when an active supplier/open PO exists; otherwise they default to Make.
                </x-backend.page-note>
            </div>
            <p class="text-muted mb-0">Material Requirement Plan for branch manufacturing and procurement.</p>
        </div>
        <a href="{{ route('planning.material-requirement-plans.index') }}"
           class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button">
            <iconify-icon icon="mdi:arrow-left"></iconify-icon>
            <span>Back to list</span>
        </a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card mb-24">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <h5 class="card-title mb-0">Plan Overview</h5>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                @if ($plan->status === \App\Enums\Reporting\MrpStatus::Draft)
                    @perm('planning.material-requirement-plans.approve', 'edit')
                        <form method="POST" action="{{ route('planning.material-requirement-plans.approve', $plan) }}"
                              class="d-inline mrp-confirm-form"
                              data-title="Approve this Material Requirement Plan?"
                              data-text="This will freeze the calculated component requirements.">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                    class="btn btn-success btn-sm px-14 py-8 radius-8 app-action-button">
                                <iconify-icon icon="mdi:check-decagram-outline"></iconify-icon>
                                <span>Approve MRP</span>
                            </button>
                        </form>
                    @endperm
                @endif

                @if ($plan->status !== \App\Enums\Reporting\MrpStatus::Cancelled)
                    @perm('planning.material-requirement-plans.cancel', 'edit')
                        <form method="POST" action="{{ route('planning.material-requirement-plans.cancel', $plan) }}"
                              class="d-inline mrp-confirm-form"
                              data-title="Cancel this Material Requirement Plan?"
                              data-text="A Cancelled MRP cannot be used for purchase or production orders.">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                    class="btn btn-outline-danger btn-sm px-14 py-8 radius-8 app-action-button">
                                <iconify-icon icon="mdi:cancel"></iconify-icon>
                                <span>Cancel MRP</span>
                            </button>
                        </form>
                    @endperm
                @endif
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-3 col-sm-6">
                    <small class="text-muted d-block">Target Month</small>
                    <span class="fw-semibold">{{ sprintf('%04d-%02d', $plan->period->year, $plan->period->month) }}</span>
                </div>
                <div class="col-lg-3 col-sm-6">
                    <small class="text-muted d-block">Branch</small>
                    <span class="fw-semibold">{{ $plan->branch->name }} ({{ $plan->branch->code }})</span>
                </div>
                <div class="col-lg-3 col-sm-6">
                    <small class="text-muted d-block">Source Demand Plan</small>
                    <a href="{{ route('planning.demand-plans.show', $plan->demandPlan) }}" class="fw-semibold hover-text-primary">
                        {{ $plan->demandPlan->plan_no }}
                    </a>
                </div>
                <div class="col-lg-3 col-sm-6">
                    <small class="text-muted d-block">Generated By</small>
                    <span class="fw-semibold">{{ $plan->generator?->name ?: 'System' }}</span>
                    <small class="text-muted d-block">{{ $plan->created_at?->format('d M Y, h:i A') }}</small>
                </div>
                @if ($plan->approved_at)
                    <div class="col-lg-3 col-sm-6">
                        <small class="text-muted d-block">Approved By</small>
                        <span class="fw-semibold">{{ $plan->approver?->name ?: 'System' }}</span>
                        <small class="text-muted d-block">{{ $plan->approved_at->format('d M Y, h:i A') }}</small>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @php
        $format = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');
    @endphp

    {{-- Finished Product Plan Table --}}
    <div class="card mb-24">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <div>
                <h5 class="card-title mb-0">Finished Products (Demand vs Stock Offset)</h5>
                <small class="text-muted">Net assembly quantity required after subtracting current warehouse inventory.</small>
            </div>
            <span class="badge bg-lilac-100 text-lilac-600 px-12 py-7 radius-4">
                {{ $plan->finishedProductLines->count() }} products
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table bordered-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 60px">#</th>
                            <th>Finished Product</th>
                            <th class="text-end">Demand Forecast</th>
                            <th class="text-end">Safety Target</th>
                            <th class="text-end">Total Needed</th>
                            <th class="text-end">On-Hand Stock</th>
                            <th class="text-end text-primary-600">Net To Produce</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($plan->finishedProductLines as $fpLine)
                            @php
                                $totalNeeded = (float) $fpLine->demand_quantity + (float) $fpLine->safety_quantity;
                            @endphp
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>
                                    <span class="fw-semibold d-block">{{ $fpLine->finishedProduct->name }}</span>
                                    <code>{{ $fpLine->finishedProduct->product_code }}</code>
                                </td>
                                <td class="text-end">{{ $format($fpLine->demand_quantity) }}</td>
                                <td class="text-end">{{ $format($fpLine->safety_quantity) }}</td>
                                <td class="text-end fw-semibold">{{ $format($totalNeeded) }}</td>
                                <td class="text-end text-muted">{{ $format($fpLine->available_stock) }}</td>
                                <td class="text-end fw-bold text-primary-600">
                                    {{ $format($fpLine->net_required) }} {{ $fpLine->finishedProduct->baseUnit?->code }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Exploded Components Table --}}
    <div class="card mb-24">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <div>
                <h5 class="card-title mb-0">Required Components (BOM Explosion & Net Procurement/Production)</h5>
                <small class="text-muted">Calculated through active Bills of Material based on net product requirements.</small>
            </div>
            <span class="badge bg-info-focus text-info-600 px-12 py-7 radius-4">
                {{ $plan->componentLines->count() }} components
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table bordered-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 60px">#</th>
                            <th>Component Type</th>
                            <th>Item Name & Code</th>
                            <th>Unit</th>
                            <th class="text-end">Gross Requirement</th>
                            <th class="text-end">Safety Buffer</th>
                            <th class="text-end">On-Hand Stock</th>
                            <th class="text-end">Incoming PO</th>
                            <th class="text-end text-danger-600">Net Shortage / Need</th>
                            <th>Suggested Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($plan->componentLines as $cLine)
                            @php
                                $unit = $cLine->component_type === 'RAW_MATERIAL'
                                    ? $cLine->rawMaterial?->baseUnit?->code
                                    : $cLine->part?->baseUnit?->code;
                            @endphp
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>
                                    @if ($cLine->component_type === 'RAW_MATERIAL')
                                        <span class="badge bg-warning-focus text-warning-main px-12 py-7 radius-4">Raw Material</span>
                                    @else
                                        <span class="badge bg-lilac-50 text-lilac-600 px-12 py-7 radius-4">Manufactured Part</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="fw-semibold d-block">{{ $cLine->componentName() }}</span>
                                    <code>{{ $cLine->componentCode() }}</code>
                                </td>
                                <td>{{ $unit ?? '—' }}</td>
                                <td class="text-end">{{ $format($cLine->gross_requirement) }}</td>
                                <td class="text-end text-muted">{{ $format($cLine->safety_quantity) }}</td>
                                <td class="text-end text-muted">{{ $format($cLine->available_stock) }}</td>
                                <td class="text-end text-info-main">{{ $format($cLine->incoming_quantity) }}</td>
                                <td class="text-end fw-bold {{ (float) $cLine->net_requirement > 0 ? 'text-danger-main' : 'text-success-main' }}">
                                    {{ $format($cLine->net_requirement) }}
                                </td>
                                <td>
                                    @if ((float) $cLine->net_requirement > 0)
                                        @if ($cLine->source_hint === 'PURCHASE')
                                            <span class="badge bg-danger-focus text-danger-main px-12 py-7 radius-4 d-inline-flex align-items-center gap-1">
                                                <iconify-icon icon="mdi:cart-arrow-down"></iconify-icon>
                                                <span>Buy {{ $format($cLine->buy_quantity) }}</span>
                                            </span>
                                        @else
                                            <span class="badge bg-info-focus text-info-main px-12 py-7 radius-4 d-inline-flex align-items-center gap-1">
                                                <iconify-icon icon="mdi:factory"></iconify-icon>
                                                <span>Make {{ $format($cLine->make_quantity) }}</span>
                                            </span>
                                        @endif
                                    @else
                                        <span class="badge bg-success-focus text-success-main px-12 py-7 radius-4 d-inline-flex align-items-center gap-1">
                                            <iconify-icon icon="mdi:check-circle-outline"></iconify-icon>
                                            <span>In Stock</span>
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-24">
                                    No components are required because current Finished Product stock covers the approved plan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Actionable Purchase Recommendations --}}
    @php
        $recs = $recommendations ?? [
            'total_items' => 0,
            'total_estimated_cost' => '0.00',
            'total_suppliers' => 0,
            'suppliers' => [],
            'unmapped_items' => [],
        ];
    @endphp

    <div class="card mb-24">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <div>
                <h5 class="card-title mb-0">Actionable Purchase Recommendations</h5>
                <small class="text-muted">Procurement shortage grouped by preferred supplier with estimated purchase rates.</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-lilac-100 text-lilac-600 px-12 py-7 radius-4">
                    {{ $recs['total_items'] }} items to procure
                </span>
                <span class="badge bg-success-focus text-success-600 px-12 py-7 radius-4">
                    Est. Cost: {{ number_format((float) $recs['total_estimated_cost'], 2) }}
                </span>
            </div>
        </div>

        @if (empty($recs['suppliers']) && empty($recs['unmapped_items']))
            <div class="card-body text-center py-32">
                <div class="d-flex justify-content-center mb-8">
                    <iconify-icon icon="mdi:check-circle-outline" class="text-success-main" style="font-size: 40px;"></iconify-icon>
                </div>
                <h6 class="fw-semibold mb-4">No Purchase Orders Needed</h6>
                <p class="text-muted text-sm mb-0">All raw material and component requirements are fully covered by current inventory.</p>
            </div>
        @else
            <div class="card-body p-0">
                @foreach ($recs['suppliers'] as $suppGroup)
                    @php
                        $linkedOrder = $plan->purchaseOrders
                            ->first(fn ($order) => (int) $order->supplier_id === (int) $suppGroup['supplier_id']
                                && $order->status !== \App\Enums\Purchase\PurchaseOrderStatus::Cancelled);
                    @endphp
                    <div class="p-16 border-bottom bg-light d-flex align-items-center justify-content-between gap-3 flex-wrap">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <iconify-icon icon="mdi:truck-outline" class="text-primary-main text-xl"></iconify-icon>
                            <h6 class="card-title mb-0 fw-semibold">{{ $suppGroup['supplier_name'] }}</h6>
                            @if ($suppGroup['supplier_code'])
                                <code>{{ $suppGroup['supplier_code'] }}</code>
                            @endif
                            @if ($suppGroup['is_preferred'])
                                <span class="badge bg-success-focus text-success-main px-12 py-7 radius-4">Preferred Supplier</span>
                            @endif
                            @if ($suppGroup['lead_days'] > 0)
                                <span class="badge bg-info-focus text-info-main px-12 py-7 radius-4">Lead Time: {{ $suppGroup['lead_days'] }} days</span>
                            @endif
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <span class="text-muted text-sm">
                                Supplier Est. Total: <strong class="text-primary-main">{{ number_format((float) $suppGroup['supplier_estimated_cost'], 2) }}</strong>
                            </span>
                            @if ($linkedOrder)
                                <a href="{{ route('purchase.orders.show', $linkedOrder) }}"
                                   class="btn btn-outline-primary btn-sm px-14 py-8 radius-8 app-action-button d-inline-flex align-items-center gap-1">
                                    <iconify-icon icon="mdi:file-document-check-outline"></iconify-icon>
                                    <span>View {{ $linkedOrder->order_no }}</span>
                                </a>
                            @elseif ($plan->status === \App\Enums\Reporting\MrpStatus::Approved)
                            @perm('purchase.orders.create', 'add')
                                <a href="{{ route('purchase.orders.create', ['mrp_id' => $plan->id, 'supplier_id' => $suppGroup['supplier_id']]) }}"
                                   class="btn btn-primary btn-sm px-14 py-8 radius-8 app-action-button app-primary-action d-inline-flex align-items-center gap-1">
                                    <iconify-icon icon="mdi:cart-plus"></iconify-icon>
                                    <span>Create Purchase Order</span>
                                </a>
                            @endperm
                            @endif
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table bordered-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 50px">#</th>
                                    <th>Item Type</th>
                                    <th>Item Name & Code</th>
                                    <th class="text-end">Required Qty</th>
                                    <th>Unit</th>
                                    <th class="text-end">Est. Unit Rate</th>
                                    <th class="text-end">Est. Line Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($suppGroup['items'] as $item)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td>
                                            @if ($item['domain'] === 'raw_material')
                                                <span class="badge bg-warning-focus text-warning-main px-12 py-7 radius-4">Raw Material</span>
                                            @else
                                                <span class="badge bg-lilac-50 text-lilac-600 px-12 py-7 radius-4">Part</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="fw-semibold d-block">{{ $item['item_name'] }}</span>
                                            <code>{{ $item['item_code'] }}</code>
                                        </td>
                                        <td class="text-end fw-bold text-danger-main">{{ $item['quantity_formatted'] }}</td>
                                        <td>{{ $item['unit_code'] }}</td>
                                        <td class="text-end">{{ $item['estimated_unit_rate'] }}</td>
                                        <td class="text-end fw-semibold">{{ number_format((float) $item['estimated_total'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach

                @if (!empty($recs['unmapped_items']))
                    <div class="p-16 border-bottom bg-warning-subtle d-flex align-items-center justify-content-between gap-2 flex-wrap">
                        <div class="d-flex align-items-center gap-2">
                            <iconify-icon icon="mdi:alert-outline" class="text-warning text-xl"></iconify-icon>
                            <h6 class="card-title mb-0 fw-semibold text-warning-emphasis">Items Without Mapped Supplier</h6>
                        </div>
                        <span class="badge bg-warning text-dark px-12 py-7 radius-4">{{ count($recs['unmapped_items']) }} items</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table bordered-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 50px">#</th>
                                    <th>Item Type</th>
                                    <th>Item Name & Code</th>
                                    <th class="text-end">Required Qty</th>
                                    <th>Unit</th>
                                    <th class="text-end">Est. Unit Rate</th>
                                    <th class="text-end">Est. Line Total</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recs['unmapped_items'] as $item)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td>
                                            @if ($item['domain'] === 'raw_material')
                                                <span class="badge bg-warning-focus text-warning-main px-12 py-7 radius-4">Raw Material</span>
                                            @else
                                                <span class="badge bg-lilac-50 text-lilac-600 px-12 py-7 radius-4">Part</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="fw-semibold d-block">{{ $item['item_name'] }}</span>
                                            <code>{{ $item['item_code'] }}</code>
                                        </td>
                                        <td class="text-end fw-bold text-danger-main">{{ $item['quantity_formatted'] }}</td>
                                        <td>{{ $item['unit_code'] }}</td>
                                        <td class="text-end">{{ $item['estimated_unit_rate'] }}</td>
                                        <td class="text-end fw-semibold">{{ number_format((float) $item['estimated_total'], 2) }}</td>
                                        <td>
                                            @if ($item['domain'] === 'raw_material')
                                                <a href="{{ route('inventory.raw-material-suppliers.index') }}" class="btn btn-outline-secondary btn-sm px-10 py-4 radius-6" target="_blank">
                                                    <span>Map Supplier</span>
                                                </a>
                                            @else
                                                <a href="{{ route('inventory.part-suppliers.index') }}" class="btn btn-outline-secondary btn-sm px-10 py-4 radius-6" target="_blank">
                                                    <span>Map Supplier</span>
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    </div>
@endsection

@section('script')
    <script>
        $('.mrp-confirm-form').on('submit', function(event) {
            event.preventDefault();
            const form = this;
            Swal.fire({
                icon: 'question',
                title: $(form).data('title'),
                text: $(form).data('text'),
                showCancelButton: true,
                confirmButtonText: 'Confirm',
            }).then(function(result) {
                if (result.isConfirmed) form.submit();
            });
        });
    </script>
@endsection
