<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $columns = ['id', 'name', 'slug', 'email', 'phone', 'address', 'postal_code', 'is_active'];
        $draw = (int) $request->input('draw');
        $start = max(0, (int) $request->input('start', 0));
        $length = min(100, max(1, (int) $request->input('length', 10)));
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));

        $query = Supplier::query()->select($columns);
        $total = (clone $query)->count();

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $orderColumn = $columns[$orderIndex] ?? 'id';
        $suppliers = $query->orderBy($orderColumn, $orderDirection)
            ->skip($start)
            ->take($length)
            ->get();

        $data = $suppliers->map(function (Supplier $supplier): array {
            $status = $supplier->is_active
                ? '<span class="badge text-sm fw-semibold bg-dark-success-gradient px-20 py-9 radius-4 text-white">Active</span>'
                : '<span class="badge text-sm fw-semibold bg-dark-warning-gradient px-20 py-9 radius-4 text-white">Inactive</span>';

            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">
                <a href="'.route('supplier.edit', $supplier).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main" data-size="lg" data-onsuccess="SupplierIndex.onSaved" title="Edit" aria-label="Edit supplier">
                    <iconify-icon icon="lucide:edit"></iconify-icon>
                </a>
                <a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-branch-delete" data-id="'.$supplier->id.'" data-url="'.route('supplier.destroy', $supplier).'" title="Delete" aria-label="Delete supplier">
                    <iconify-icon icon="mdi:delete"></iconify-icon>
                </a>
            </div>';

            return [
                $supplier->id,
                '<strong>'.e($supplier->name).'</strong>',
                e((string) $supplier->slug),
                e((string) $supplier->email),
                e((string) $supplier->phone),
                e((string) $supplier->address),
                e((string) $supplier->postal_code),
                $status,
                $actions,
            ];
        })->all();

        return response()->json([
            'draw' => $draw,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $data,
        ]);
    }

    public function create(): View
    {
        return view('backend.modules.suppliers.create');
    }

    public function createModal(): View
    {
        return view('backend.modules.suppliers.createModal');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedData($request);

        if ($request->hasFile('image')) {
            $data['image'] = uploadImage($request->file('image'), 'supplier/images');
        }

        $data['name'] = ucwords($data['name']);
        $data['is_active'] = $request->boolean('is_active', true);
        $supplier = Supplier::create($data);

        return response()->json([
            'ok' => true,
            'msg' => 'Supplier created successfully.',
            'id' => $supplier->id,
        ]);
    }

    public function editModal(Supplier $supplier): View
    {
        return view('backend.modules.suppliers.edit', compact('supplier'));
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return response()->json($supplier->only(['id', 'code', 'name', 'slug', 'email', 'phone', 'address', 'postal_code', 'is_active']));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $this->validatedData($request, $supplier);

        if ($request->hasFile('image')) {
            $data['image'] = uploadImage($request->file('image'), 'supplier/images');
        }

        $data['name'] = ucwords($data['name']);
        $data['is_active'] = $request->boolean('is_active');
        $supplier->update($data);

        return redirect()->route('supplier.index')
            ->with('success', 'Supplier updated successfully.');
    }

    public function importCsvModal(): View
    {
        return view('backend.modules.suppliers.import_csv');
    }

    public function importCsv(Request $request): JsonResponse
    {
        $rows = $request->json()->all();

        if (! is_array($rows) || $rows === []) {
            return response()->json(['message' => 'The uploaded file contains no rows.'], 422);
        }

        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, &$imported, &$skipped): void {
            foreach ($rows as $rawRow) {
                if (! is_array($rawRow)) {
                    $skipped++;
                    continue;
                }

                $row = [];
                foreach ($rawRow as $key => $value) {
                    $row[strtolower(trim((string) $key))] = is_string($value) ? trim($value) : $value;
                }

                $name = $this->nullableString($row['name'] ?? null);
                $code = $this->nullableString($row['code'] ?? null);
                $phone = $this->nullableString($row['phone'] ?? null);
                $email = $this->nullableString($row['email'] ?? null);

                if ($name === null || ($code === null && $phone === null && $email === null)) {
                    $skipped++;
                    continue;
                }

                $identity = $code !== null
                    ? ['code' => $code]
                    : ($phone !== null ? ['phone' => $phone] : ['email' => $email]);

                Supplier::updateOrCreate($identity, [
                    'name' => ucwords($name),
                    'slug' => $this->nullableString($row['slug'] ?? null),
                    'email' => $email,
                    'phone' => $phone,
                    'address' => $this->nullableString($row['address'] ?? null),
                    'postal_code' => $this->nullableString($row['postal_code'] ?? null),
                    'is_active' => filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
                ]);

                $imported++;
            }
        });

        return response()->json([
            'ok' => true,
            'msg' => "{$imported} supplier(s) imported; {$skipped} row(s) skipped.",
            'imported' => $imported,
            'skipped' => $skipped,
        ]);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->delete();

        return response()->json(['ok' => true, 'msg' => 'Supplier deleted successfully.']);
    }

    public function select2(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $query = Supplier::query()->where('is_active', true);

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $items = $query->orderBy('name')->limit(20)->get(['id', 'name', 'code']);

        return response()->json([
            'results' => $items->map(fn (Supplier $supplier): array => [
                'id' => $supplier->id,
                'text' => $supplier->code ? "{$supplier->name} ({$supplier->code})" : $supplier->name,
            ]),
        ]);
    }

    private function validatedData(Request $request, ?Supplier $supplier = null): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:50', Rule::unique('suppliers', 'code')->ignore($supplier?->id)],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191', Rule::unique('suppliers', 'email')->ignore($supplier?->id)],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique('suppliers', 'phone')->ignore($supplier?->id)],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
