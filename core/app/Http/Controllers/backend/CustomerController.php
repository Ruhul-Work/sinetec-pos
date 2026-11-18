<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index()
    {
        return view('backend.modules.customers.index');
    }

    public function listAjax(Request $request)
    {
        $columns = ['id', 'name', 'slug', 'email', 'birth_date', 'phone', 'postal_code', 'address', 'is_active'];
        $draw = (int) $request->input('draw');
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 10);
        $orderIdx = (int) $request->input('order.0.column', 0);
        $orderDir = strtolower($request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $searchVal = trim($request->input('search.value', ''));

        $base = Customer::query()
            ->select(['id', 'name', 'slug', 'email', 'birth_date', 'phone', 'postal_code', 'address', 'is_active']);

        $total = (clone $base)->count();

        if ($searchVal !== '') {
            $base->where(function ($q) use ($searchVal) {
                $q->where('name', 'like', "%{$searchVal}%")
                    ->orWhere('slug', 'like', "%{$searchVal}%")
                    ->orWhere('email', 'like', "%{$searchVal}%");
            });
        }

        $filtered = (clone $base)->count();

        $orderCol = $columns[$orderIdx] ?? 'id';

        $rows = $base->orderBy($orderCol, $orderDir)
            ->skip($start)->take($length)->get();

        $data = [];
        foreach ($rows as $b) {
            $nameCol = '<strong>'.e($b->name).'</strong>';

            $active = $b->is_active
                ? '<span class="badge text-sm fw-semibold bg-dark-success-gradient px-20 py-9 radius-4 text-white">Active</span>'
                : '<span class="badge text-sm fw-semibold bg-dark-warning-gradient px-20 py-9 radius-4 text-white">Inactive</span>';

            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">
                <a href="'.route('customer.edit', $b->id).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center
                    bg-success-focus text-success-main "
                    data-size="lg"
                    data-onload="customerIndex.onLoad"
                    data-onsuccess="customerIndex.onSaved"
                    title="Edit">
                    <iconify-icon icon="lucide:edit"></iconify-icon>
                </a>
                <a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-branch-delete"
                    data-id="'.$b->id.'"
                    data-url="'.route('customer.destroy', $b->id).'"
                    title="Delete">
                    <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
                </a>
            </div>';

            $data[] = [
                $b->id,
                $nameCol,
                e($b->slug),
                $b->email,
                $b->birth_date ?? 'N/A',
                $b->phone,
                ucwords($b->address),
                $b->postal_code,
                $active,
                $actions,
            ];
        }

        return response()->json([
            'draw' => $draw,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $data,
        ]);
    }

    public function createModal(): View
    {
        return view('backend.modules.customers.modal');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50', Rule::unique('customers', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:191', Rule::unique('customers', 'email')],
            'phone' => ['required', 'string', 'max:32', Rule::unique('customers', 'phone')],
            'alternate_phone' => ['nullable', 'string', 'max:32'],
            'birth_date' => ['nullable', 'date'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $name = trim($data['name']);
        $slug = Str::slug($name);
        $customer = Customer::query()->create([
            'code' => $this->nullableText($data['code'] ?? null),
            'name' => $name,
            'slug' => $slug !== '' ? $slug : null,
            'email' => $this->nullableText($data['email'] ?? null),
            'phone' => trim($data['phone']),
            'alternate_phone' => $this->nullableText($data['alternate_phone'] ?? null),
            'birth_date' => $data['birth_date'] ?? null,
            'address' => $this->nullableText($data['address'] ?? null),
            'postal_code' => $this->nullableText($data['postal_code'] ?? null),
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json([
            'ok' => true,
            'msg' => 'Customer created successfully.',
            'message' => 'Customer created successfully.',
            'id' => $customer->id,
            'name' => $customer->name,
            'code' => $customer->code,
            'phone' => $customer->phone,
        ]);
    }

    public function editModal(Customer $customer)
    {
        return view('backend.modules.customers.edit', compact('customer'));
    }

    public function show(Customer $customer)
    {

        return response()->json([
            'id' => $customer->id,
            'name' => $customer->name,
            'slug' => $customer->slug,

        ]);
    }

    public function update(Request $req, Customer $customer)
    {

        $data = $req->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:50'],
            'alternate_phone' => ['nullable', 'string', 'max:50'],
            'birth_date' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['required', 'integer'],
            'address' => ['required', 'string', 'max:255'],
            'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'remove_image' => ['sometimes', 'boolean'],
            'is_active' => ['required', 'integer'],

        ]);

        $previousImage = $customer->image;

        $removeOldImage = $req->boolean('remove_image');

        if ($req->hasFile('image')) {
            $imagePath = uploadImage($req->file('image'), 'customer/images');
            $customer->image = $imagePath;
            $removeOldImage = true;
        } elseif ($removeOldImage) {
            $customer->image = null;
        }

        $customer->name = ucwords($data['name']);
        $customer->slug = $data['slug'];
        $customer->email = $data['email'];
        $customer->phone = $data['phone'];
        $customer->alternate_phone = $data['alternate_phone'] ?? null;
        $customer->birth_date = $data['birth_date'] ?? null;
        $customer->address = $data['address'];
        $customer->postal_code = $data['postal_code'];
        $customer->is_active = $data['is_active'];

        $customer->save();

        if ($removeOldImage && $previousImage !== $customer->image) {
            $this->deleteManagedImage($previousImage);
        }

        return redirect()->route('customer.index')
            ->with('success', 'customer updated successfully!');
    }

    public function importCsvModal()
    {
        return view('backend.modules.customers.import_csv');
    }

    public function importCsv(Request $req)
    {

        $sheets[] = $req->all();
        if (empty($sheets) || empty($sheets[0])) {
            return back()->with('error', 'Uploaded file is empty or unreadable.');
        }

        $rows = $sheets[0];

        // If first row is header, normalize it and map rows to assoc arrays
        $header = array_map(fn ($h) => strtolower(trim($h)), $rows[0]);
        $dataRows = array_slice($rows, 1);

        $allowed = ['name', 'slug', 'email', 'birth_date', 'phone', 'alternate_phone', 'address', 'postal_code', 'image', 'is_active']; // allowed DB columns

        $insertRows = [];

        foreach ($dataRows as $r) {
            // protect against ragged rows
            $assoc = [];
            foreach ($header as $i => $col) {
                $assoc[$col] = $r[$i] ?? null;
            }
            // whitelist and normalize
            $row = array_intersect_key($assoc, array_flip($allowed));
            $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);

            if (empty($row['phone'])) {
                continue; // skip if no unique identifier
            }
            // prepare for upsert; ensure email key exists even if null
            $insertRows[] = [
                'name' => ucwords($row['name']) ?? null,
                'slug' => $row['slug'] ?? null,
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
                'alternate_phone' => $row['alternate_phone'] ?? null,
                'address' => ucwords($row['address']) ?? null,
                'postal_code' => $row['postal_code'] ?? null,
                'birth_date' => $row['birth_date'] ?? null,
                'image' => $row['image'] ?? null,
                'is_active' => (int) ($row['is_active']) ?? 1,
            ];
        }

        if (! empty($insertRows)) {
            // Upsert in bulk (Laravel 8+). Unique by email (or phone)
            Customer::upsert($insertRows, ['name', 'slug', 'phone', 'address', 'postal_code', 'alternate_phone', 'birth_date', 'image', 'is_active']);
        }

        return response()->json(['ok' => true, 'msg' => 'Customers imported successfully']);
    }

    public function destroy(Customer $customer)
    {
        $image = $customer->image;

        $customer->delete();

        $this->deleteManagedImage($image);

        return response()->json(['ok' => true, 'msg' => 'customer deleted']);
    }

    public function select2(Request $r)
    {

        $q = trim($r->input('q', ''));
        $type = $r->type;
        $base = Customer::query()->where('is_active', 1);

        if ($q !== '') {
            $base->where(function ($x) use ($q) {
                $x->where('name', 'like', "%{$q}%");
            });
        }

        $items = $base->orderBy('id', 'desc')
            ->limit(20)->get(['id', 'name']);

        return response()->json([
            'results' => $items->map(fn ($t) => [
                'id' => $t->id,
                'text' => $t->name,
            ]),
        ]);
    }

    private function deleteManagedImage(?string $path): void
    {
        if (! $path) {
            return;
        }

        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
        if (! str_starts_with($relativePath, 'uploads/customer/images/')) {
            return;
        }

        File::delete(base_path('../'.$relativePath));
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
