<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\AbstractInventoryCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

abstract class InventoryCategoryController extends Controller
{
    /** @return class-string<AbstractInventoryCategory> */
    abstract protected function modelClass(): string;

    abstract protected function routePrefix(): string;

    abstract protected function singularLabel(): string;

    abstract protected function pluralLabel(): string;

    abstract protected function itemTable(): string;

    abstract protected function itemCategoryColumn(): string;

    public function index(): View
    {
        return view('backend.modules.inventory_category.index', $this->viewData());
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['id', 'code', 'name', 'parent_id', 'is_active', 'created_at'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));

        $modelClass = $this->modelClass();
        $query = $modelClass::query()
            ->with('parent:id,name,code')
            ->select(['id', 'parent_id', 'code', 'name', 'is_active', 'created_at']);

        $total = (clone $query)->count();

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhereHas('parent', function (Builder $parentQuery) use ($search) {
                        $parentQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }

        $filtered = (clone $query)->count();
        $orderColumn = $columns[$orderIndex] ?? 'id';
        $rows = $query->orderBy($orderColumn, $orderDirection)
            ->skip($start)
            ->take($length)
            ->get();

        $routePrefix = $this->routePrefix();
        $user = $request->user();
        $canEdit = $user?->canDo("{$routePrefix}.update", 'edit') ?? false;
        $canDelete = $user?->canDo("{$routePrefix}.destroy", 'delete') ?? false;

        $data = $rows->map(function (AbstractInventoryCategory $category) use ($routePrefix, $canEdit, $canDelete) {
            $status = $category->is_active
                ? '<span class="badge text-sm fw-semibold bg-dark-success-gradient px-20 py-9 radius-4 text-white">Active</span>'
                : '<span class="badge text-sm fw-semibold bg-dark-warning-gradient px-20 py-9 radius-4 text-white">Inactive</span>';

            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">';

            if ($canEdit) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main AjaxModal"'
                    .' data-ajax-modal="'.e(route("{$routePrefix}.editModal", $category->id)).'"'
                    .' data-size="md" data-onload="InventoryCategoriesIndex.onLoad"'
                    .' data-onsuccess="InventoryCategoriesIndex.onSaved" title="Edit">'
                    .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }

            if ($canDelete) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-inventory-category-delete"'
                    .' data-url="'.e(route("{$routePrefix}.destroy", $category->id)).'" title="Delete">'
                    .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
            }

            $actions .= '</div>';

            return [
                $category->id,
                '<code>'.e($category->code).'</code>',
                '<strong>'.e($category->name).'</strong>',
                $category->parent
                    ? e($category->parent->name).' <small class="text-muted">('.e($category->parent->code).')</small>'
                    : '<span class="text-muted">Root category</span>',
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

    public function createModal(): View
    {
        return view('backend.modules.inventory_category.modal', $this->viewData([
            'category' => null,
            'action' => route("{$this->routePrefix()}.store"),
            'method' => 'POST',
            'title' => "Add {$this->singularLabel()}",
        ]));
    }

    public function editModal(int $category): View
    {
        $record = $this->findCategory($category);

        return view('backend.modules.inventory_category.modal', $this->viewData([
            'category' => $record,
            'action' => route("{$this->routePrefix()}.update", $record->id),
            'method' => 'PUT',
            'title' => "Edit {$this->singularLabel()}",
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $modelClass = $this->modelClass();
        $category = $modelClass::create($data);

        return response()->json([
            'success' => true,
            'msg' => "{$this->singularLabel()} created successfully.",
            'id' => $category->id,
        ], 201);
    }

    public function update(Request $request, int $category): JsonResponse
    {
        $record = $this->findCategory($category);
        $record->update($this->validated($request, $record));

        return response()->json([
            'success' => true,
            'msg' => "{$this->singularLabel()} updated successfully.",
        ]);
    }

    public function destroy(int $category): JsonResponse
    {
        $record = $this->findCategory($category);

        if ($record->children()->exists()) {
            return response()->json([
                'success' => false,
                'msg' => 'This category has child categories and cannot be deleted.',
            ], 422);
        }

        if (DB::table($this->itemTable())->where($this->itemCategoryColumn(), $record->id)->exists()) {
            return response()->json([
                'success' => false,
                'msg' => 'This category is already used by an inventory item and cannot be deleted.',
            ], 422);
        }

        $record->delete();

        return response()->json([
            'success' => true,
            'msg' => "{$this->singularLabel()} deleted successfully.",
        ]);
    }

    public function select2(Request $request): JsonResponse
    {
        $modelClass = $this->modelClass();
        $search = trim((string) $request->input('q', ''));
        $exclude = $request->integer('exclude');
        $excludedIds = $exclude > 0 ? $this->descendantIds($exclude) : [];

        if ($exclude > 0) {
            $excludedIds[] = $exclude;
        }

        $query = $modelClass::query()->where('is_active', true);

        if ($excludedIds !== []) {
            $query->whereNotIn('id', array_unique($excludedIds));
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $results = $query->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'code'])
            ->map(fn (AbstractInventoryCategory $category) => [
                'id' => $category->id,
                'text' => "{$category->name} ({$category->code})",
            ]);

        return response()->json(['results' => $results]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?AbstractInventoryCategory $category = null): array
    {
        $request->merge([
            'code' => strtoupper(trim((string) $request->input('code'))),
            'name' => trim((string) $request->input('name')),
        ]);

        $modelClass = $this->modelClass();
        $table = (new $modelClass())->getTable();

        $validator = Validator::make($request->all(), [
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists($table, 'id')->whereNull('deleted_at'),
            ],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique($table, 'code')->ignore($category?->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request, $category) {
            $parentId = $request->integer('parent_id');

            $invalidParentIds = $category
                ? array_merge([$category->id], $this->descendantIds($category->id))
                : [];

            if ($category && $parentId > 0 && in_array($parentId, $invalidParentIds, true)) {
                $validator->errors()->add('parent_id', 'A category cannot be its own parent or a child of one of its descendants.');
            }
        });

        $data = $validator->validate();
        $data['parent_id'] = $data['parent_id'] ?? null;
        $data['description'] = filled($data['description'] ?? null) ? trim($data['description']) : null;
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    /** @return array<int> */
    private function descendantIds(int $parentId): array
    {
        $modelClass = $this->modelClass();
        $descendants = [];
        $pending = [$parentId];

        while ($pending !== []) {
            $children = $modelClass::query()
                ->whereIn('parent_id', $pending)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $children = array_values(array_diff($children, $descendants));
            if ($children === []) {
                break;
            }

            $descendants = array_merge($descendants, $children);
            $pending = $children;
        }

        return $descendants;
    }

    private function findCategory(int $id): AbstractInventoryCategory
    {
        $modelClass = $this->modelClass();

        return $modelClass::query()->findOrFail($id);
    }

    /** @param array<string, mixed> $extra */
    private function viewData(array $extra = []): array
    {
        return array_merge([
            'routePrefix' => $this->routePrefix(),
            'singularLabel' => $this->singularLabel(),
            'pluralLabel' => $this->pluralLabel(),
        ], $extra);
    }
}
