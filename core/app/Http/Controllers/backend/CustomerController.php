<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(): View
    {
        return view('backend.modules.customers.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['id', 'name', 'phone', 'address', 'is_active', 'id'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc'
            ? 'asc'
            : 'desc';
        $search = trim((string) $request->input('search.value', ''));
        $status = strtoupper(trim((string) $request->input('status', '')));

        $query = Customer::query()->select([
            'id',
            'code',
            'name',
            'email',
            'phone',
            'alternate_phone',
            'address',
            'postal_code',
            'birth_date',
            'is_active',
        ]);
        $total = (clone $query)->count();

        if ($status === 'ACTIVE' || $status === 'INACTIVE') {
            $query->where('is_active', $status === 'ACTIVE');
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('alternate_phone', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $customers = $query
            ->orderBy($columns[$orderIndex] ?? 'id', $orderDirection)
            ->skip($start)
            ->take($length)
            ->get();

        $user = $request->user();
        $canEdit = $user?->canDo('customer.update', 'edit') ?? false;
        $canDelete = $user?->canDo('customer.destroy', 'delete') ?? false;

        $rows = $customers->map(fn (Customer $customer): array => [
            $customer->id,
            $this->identityColumn($customer),
            $this->contactColumn($customer),
            $this->addressColumn($customer),
            $this->statusBadge($customer),
            $this->actions($customer, $canEdit, $canDelete),
        ])->all();

        return response()->json([
            'draw' => $draw,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $rows,
        ]);
    }

    public function createModal(): View
    {
        return $this->formView(
            customer: new Customer(['is_active' => true]),
            action: route('customer.store'),
            method: 'POST',
            title: 'Add Customer',
        );
    }

    public function editModal(Customer $customer): View
    {
        return $this->formView(
            customer: $customer,
            action: route('customer.update', $customer),
            method: 'PUT',
            title: 'Edit Customer',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $customer = Customer::query()->create($this->validatedData($request));

        return response()->json([
            'ok' => true,
            'success' => true,
            'msg' => 'Customer created successfully.',
            'message' => 'Customer created successfully.',
            'id' => $customer->id,
            'name' => $customer->name,
            'code' => $customer->code,
            'phone' => $customer->phone,
            'customer' => $customer->only(['id', 'code', 'name', 'phone']),
        ], 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $customer->update($this->validatedData($request, $customer));

        return response()->json([
            'ok' => true,
            'success' => true,
            'msg' => 'Customer updated successfully.',
            'customer' => $customer->only(['id', 'code', 'name', 'phone']),
        ]);
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json($customer->only([
            'id',
            'code',
            'name',
            'email',
            'phone',
            'alternate_phone',
            'birth_date',
            'address',
            'postal_code',
            'is_active',
        ]));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $reference = $this->firstReference($customer);
        if ($reference !== null) {
            return response()->json([
                'ok' => false,
                'success' => false,
                'msg' => "This customer is already used by {$reference}. Make the customer inactive instead.",
            ], 422);
        }

        $customer->delete();

        return response()->json([
            'ok' => true,
            'success' => true,
            'msg' => 'Customer deleted successfully.',
        ]);
    }

    public function select2(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $query = Customer::query()->where('is_active', true);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'code', 'name', 'phone']);

        return response()->json([
            'results' => $customers->map(fn (Customer $customer): array => [
                'id' => $customer->id,
                'text' => $this->selectLabel($customer),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedData(Request $request, ?Customer $customer = null): array
    {
        $request->merge([
            'code' => $this->nullableText($request->input('code')),
            'name' => trim((string) $request->input('name')),
            'email' => $this->nullableText($request->input('email')),
            'phone' => trim((string) $request->input('phone')),
            'alternate_phone' => $this->nullableText($request->input('alternate_phone')),
            'address' => $this->nullableText($request->input('address')),
            'postal_code' => $this->nullableText($request->input('postal_code')),
        ]);

        $data = $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'code')->ignore($customer?->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'email' => [
                'nullable',
                'email',
                'max:191',
                Rule::unique('customers', 'email')->ignore($customer?->id),
            ],
            'phone' => [
                'required',
                'string',
                'max:32',
                Rule::unique('customers', 'phone')->ignore($customer?->id),
            ],
            'alternate_phone' => ['nullable', 'string', 'max:32'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'is_active' => ['required', 'boolean'],
        ]);

        $slug = Str::slug($data['name']);

        return [
            ...$data,
            'code' => filled($data['code'] ?? null) ? strtoupper($data['code']) : null,
            'slug' => $slug !== '' ? $slug : null,
            'is_active' => $request->boolean('is_active'),
        ];
    }

    private function formView(
        Customer $customer,
        string $action,
        string $method,
        string $title,
    ): View {
        return view('backend.modules.customers.modal', compact(
            'customer',
            'action',
            'method',
            'title',
        ));
    }

    private function firstReference(Customer $customer): ?string
    {
        $references = [
            'sales' => 'Sales',
            'customer_receipts' => 'Customer Receipts',
            'coupon_usages' => 'Coupon Usage',
            'loyalty_transactions' => 'Loyalty Transactions',
        ];

        foreach ($references as $table => $label) {
            if (DB::table($table)->where('customer_id', $customer->id)->exists()) {
                return $label;
            }
        }

        return null;
    }

    private function identityColumn(Customer $customer): string
    {
        $code = $customer->code
            ? '<div class="text-muted text-xs"><code>'.e($customer->code).'</code></div>'
            : '';

        return '<strong>'.e($customer->name).'</strong>'.$code;
    }

    private function contactColumn(Customer $customer): string
    {
        $email = $customer->email
            ? '<div class="text-muted text-xs">'.e($customer->email).'</div>'
            : '';

        return '<span class="fw-medium">'.e($customer->phone).'</span>'.$email;
    }

    private function addressColumn(Customer $customer): string
    {
        $postalCode = $customer->postal_code
            ? '<div class="text-muted text-xs">Postal: '.e($customer->postal_code).'</div>'
            : '';

        return e($customer->address ?: '—').$postalCode;
    }

    private function statusBadge(Customer $customer): string
    {
        if ($customer->is_active) {
            return '<span class="badge bg-dark-success-gradient text-white px-16 py-7 radius-4">Active</span>';
        }

        return '<span class="badge bg-dark-warning-gradient text-white px-16 py-7 radius-4">Inactive</span>';
    }

    private function actions(Customer $customer, bool $canEdit, bool $canDelete): string
    {
        $actions = '<div class="d-inline-flex justify-content-end align-items-center gap-1 w-100">';

        if ($canEdit) {
            $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex '
                .'align-items-center justify-content-center bg-success-focus text-success-main AjaxModal" '
                .'data-ajax-modal="'.e(route('customer.edit', $customer)).'" data-size="lg" '
                .'data-onsuccess="CustomerIndex.onSaved" title="Edit customer" aria-label="Edit customer">'
                .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
        }

        if ($canDelete) {
            $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex '
                .'align-items-center justify-content-center bg-danger-focus text-danger-main '
                .'btn-customer-delete" data-url="'.e(route('customer.destroy', $customer)).'" '
                .'title="Delete customer" aria-label="Delete customer">'
                .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
        }

        return $actions.'</div>';
    }

    private function selectLabel(Customer $customer): string
    {
        $details = array_filter([$customer->code, $customer->phone]);

        return $details === []
            ? $customer->name
            : $customer->name.' ('.implode(' · ', $details).')';
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
