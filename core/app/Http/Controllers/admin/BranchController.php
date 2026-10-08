<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\backend\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    public function index()
    {
        return view('backend.modules.branches.index');
    }

    public function listAjax(Request $request)
    {
        $columns = ['id', 'name', 'code', 'phone', 'address', 'is_active'];
        $draw = (int) $request->input('draw');
        $start = (int) $request->input('start', 0);
        $length = min(max((int) $request->input('length', 10), 1), 100);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower($request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim($request->input('search.value', ''));

        $query = Branch::query()->select(['id', 'name', 'code', 'phone', 'address', 'is_active']);
        $total = (clone $query)->count();

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $branches = $query
            ->orderBy($columns[$orderIndex] ?? 'id', $orderDirection)
            ->offset($start)
            ->limit($length)
            ->get();

        return response()->json([
            'draw' => $draw,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $branches->map(function (Branch $branch) {
                $status = $branch->is_active
                    ? '<span class="badge text-sm fw-semibold bg-dark-success-gradient px-20 py-9 radius-4 text-white">Active</span>'
                    : '<span class="badge text-sm fw-semibold bg-dark-warning-gradient px-20 py-9 radius-4 text-white">Inactive</span>';

                $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">
                    <a href="#"
                        class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main AjaxModal"
                        data-ajax-modal="' . route('org.branches.editModal', $branch) . '"
                        data-size="lg"
                        data-onsuccess="BranchesIndex.onSaved"
                        title="Edit branch"
                        aria-label="Edit branch">
                        <iconify-icon icon="lucide:edit"></iconify-icon>
                    </a>
                    <button type="button"
                        class="border-0 w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-branch-delete"
                        data-url="' . route('org.branches.destroy', $branch) . '"
                        title="Delete branch"
                        aria-label="Delete branch">
                        <iconify-icon icon="mdi:delete"></iconify-icon>
                    </button>
                </div>';

                return [
                    $branch->id,
                    '<strong>' . e($branch->name) . '</strong><br><small>' . e($branch->code) . '</small>',
                    e($branch->phone ?? '—'),
                    e($branch->address ?? '—'),
                    $status,
                    $actions,
                ];
            })->all(),
        ]);
    }

    public function createModal()
    {
        return view('backend.modules.branches.create_modal');
    }

    public function store(Request $request)
    {
        $branch = Branch::create($this->validatedData($request));

        return response()->json(['ok' => true, 'msg' => 'Branch created.', 'id' => $branch->id]);
    }

    public function editModal(Branch $branch)
    {
        return view('backend.modules.branches.edit_modal', compact('branch'));
    }

    public function show(Branch $branch)
    {
        return response()->json($branch->only(['id', 'name', 'code', 'phone', 'email', 'address', 'is_active']));
    }

    public function update(Request $request, Branch $branch)
    {
        $branch->update($this->validatedData($request, $branch));

        return response()->json(['ok' => true, 'msg' => 'Branch updated.']);
    }

    public function destroy(Branch $branch)
    {
        if (DB::table('users')->where('branch_id', $branch->id)->exists()) {
            return response()->json(['ok' => false, 'msg' => 'Reassign users before deleting this branch.'], 422);
        }

        $branch->delete();

        return response()->json(['ok' => true, 'msg' => 'Branch deleted.']);
    }

    public function select2(Request $request)
    {
        $search = trim($request->input('q', ''));
        $branches = Branch::query()
            ->where('is_active', true)
            ->when($search, function ($query) use ($search) {
                $query->where(fn ($builder) => $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%"));
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'code']);

        return response()->json([
            'results' => $branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'text' => $branch->name . ($branch->code ? " ({$branch->code})" : ''),
            ]),
        ]);
    }

    private function validatedData(Request $request, ?Branch $branch = null): array
    {
        $uniqueCode = 'unique:branches,code' . ($branch ? ',' . $branch->id : '');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:50', $uniqueCode],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['name'] = trim($data['name']);
        $data['code'] = strtoupper(trim($data['code']));
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
