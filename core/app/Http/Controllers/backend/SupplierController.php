<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(): View
    {
        return view('backend.modules.suppliers.index');
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

        $query = Supplier::query()->select([
            'id',
            'code',
            'name',
            'email',
            'phone',
            'address',
            'postal_code',
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
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $suppliers = $query
            ->orderBy($columns[$orderIndex] ?? 'id', $orderDirection)
            ->skip($start)
            ->take($length)
            ->get();

        $user = $request->user();
        $canEdit = $user?->canDo('supplier.update', 'edit') ?? false;
        $canDelete = $user?->canDo('supplier.destroy', 'delete') ?? false;

        $rows = $suppliers->map(fn (Supplier $supplier): array => [
            $supplier->id,
            $this->identityColumn($supplier),
            $this->contactColumn($supplier),
            $this->addressColumn($supplier),
            $this->statusBadge($supplier),
            $this->actions($supplier, $canEdit, $canDelete),
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
            supplier: new Supplier(['is_active' => true]),
            action: route('supplier.store'),
            method: 'POST',
            title: 'Add Supplier',
        );
    }

    public function editModal(Supplier $supplier): View
    {
        return $this->formView(
            supplier: $supplier,
            action: route('supplier.update', $supplier),
            method: 'PUT',
            title: 'Edit Supplier',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $supplier = Supplier::query()->create($this->validatedData($request));

        return response()->json([
            'ok' => true,
            'success' => true,
            'msg' => 'Supplier created successfully.',
            'message' => 'Supplier created successfully.',
            'id' => $supplier->id,
            'name' => $supplier->name,
            'code' => $supplier->code,
            'supplier' => $supplier->only(['id', 'code', 'name', 'phone']),
        ], 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($this->validatedData($request, $supplier));

        return response()->json([
            'ok' => true,
            'success' => true,
            'msg' => 'Supplier updated successfully.',
            'supplier' => $supplier->only(['id', 'code', 'name', 'phone']),
        ]);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return response()->json($supplier->only([
            'id',
            'code',
            'name',
            'email',
            'phone',
            'address',
            'postal_code',
            'is_active',
        ]));
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $reference = $this->firstReference($supplier);
        if ($reference !== null) {
            return response()->json([
                'ok' => false,
                'success' => false,
                'msg' => "This supplier is already used by {$reference}. Make the supplier inactive instead.",
            ], 422);
        }

        $supplier->delete();

        return response()->json([
            'ok' => true,
            'success' => true,
            'msg' => 'Supplier deleted successfully.',
        ]);
    }

    public function select2(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $query = Supplier::query()->where('is_active', true);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $suppliers = $query
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'code', 'phone']);

        return response()->json([
            'results' => $suppliers->map(fn (Supplier $supplier): array => [
                'id' => $supplier->id,
                'text' => $this->selectLabel($supplier),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedData(Request $request, ?Supplier $supplier = null): array
    {
        $request->merge([
            'code' => $this->nullableText($request->input('code')),
            'name' => trim((string) $request->input('name')),
            'email' => $this->nullableText($request->input('email')),
            'phone' => $this->nullableText($request->input('phone')),
            'address' => $this->nullableText($request->input('address')),
            'postal_code' => $this->nullableText($request->input('postal_code')),
        ]);

        $data = $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('suppliers', 'code')->ignore($supplier?->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'email' => [
                'nullable',
                'email',
                'max:191',
                Rule::unique('suppliers', 'email')->ignore($supplier?->id),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('suppliers', 'phone')->ignore($supplier?->id),
            ],
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
        Supplier $supplier,
        string $action,
        string $method,
        string $title,
    ): View {
        return view('backend.modules.suppliers.modal', compact(
            'supplier',
            'action',
            'method',
            'title',
        ));
    }

    private function firstReference(Supplier $supplier): ?string
    {
        $references = [
            'raw_material_suppliers' => 'Raw Material Supplier Mappings',
            'part_suppliers' => 'Part Supplier Mappings',
            'purchase_order' => 'Purchase Orders',
            'purchase_receipt' => 'Purchase Receipts',
            'purchase_return' => 'Purchase Returns',
            'purchase_payment' => 'Supplier Payments',
        ];

        foreach ($references as $table => $label) {
            if (DB::table($table)->where('supplier_id', $supplier->id)->exists()) {
                return $label;
            }
        }

        return null;
    }

    private function identityColumn(Supplier $supplier): string
    {
        $code = $supplier->code
            ? '<div class="text-muted text-xs"><code>'.e($supplier->code).'</code></div>'
            : '';

        return '<strong>'.e($supplier->name).'</strong>'.$code;
    }

    private function contactColumn(Supplier $supplier): string
    {
        $email = $supplier->email
            ? '<div class="text-muted text-xs">'.e($supplier->email).'</div>'
            : '';

        return '<span class="fw-medium">'.e($supplier->phone ?: '—').'</span>'.$email;
    }

    private function addressColumn(Supplier $supplier): string
    {
        $postalCode = $supplier->postal_code
            ? '<div class="text-muted text-xs">Postal: '.e($supplier->postal_code).'</div>'
            : '';

        return e($supplier->address ?: '—').$postalCode;
    }

    private function statusBadge(Supplier $supplier): string
    {
        if ($supplier->is_active) {
            return '<span class="badge bg-dark-success-gradient text-white px-16 py-7 radius-4">Active</span>';
        }

        return '<span class="badge bg-dark-warning-gradient text-white px-16 py-7 radius-4">Inactive</span>';
    }

    private function actions(Supplier $supplier, bool $canEdit, bool $canDelete): string
    {
        $actions = '<div class="d-inline-flex justify-content-end align-items-center gap-1 w-100">';

        if ($canEdit) {
            $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex '
                .'align-items-center justify-content-center bg-success-focus text-success-main AjaxModal" '
                .'data-ajax-modal="'.e(route('supplier.edit', $supplier)).'" data-size="lg" '
                .'data-onsuccess="SupplierIndex.onSaved" title="Edit supplier" aria-label="Edit supplier">'
                .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
        }

        if ($canDelete) {
            $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex '
                .'align-items-center justify-content-center bg-danger-focus text-danger-main '
                .'btn-supplier-delete" data-url="'.e(route('supplier.destroy', $supplier)).'" '
                .'title="Delete supplier" aria-label="Delete supplier">'
                .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
        }

        return $actions.'</div>';
    }

    private function selectLabel(Supplier $supplier): string
    {
        $details = array_filter([$supplier->code, $supplier->phone]);

        return $details === []
            ? $supplier->name
            : $supplier->name.' ('.implode(' · ', $details).')';
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
