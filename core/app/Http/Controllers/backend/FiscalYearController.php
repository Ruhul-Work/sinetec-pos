<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\FiscalYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FiscalYearController extends Controller
{
    public function index(): View
    {
        return view('backend.modules.fiscal_years.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['id', 'name', 'start_date', 'end_date', 'is_active', 'id'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = min(max((int) $request->input('length', 10), 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $direction = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));
        $query = FiscalYear::query();
        $total = (clone $query)->count();
        $query->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        $filtered = (clone $query)->count();
        $rows = $query->orderBy($columns[$orderIndex] ?? 'id', $direction)->skip($start)->take($length)->get();
        $data = $rows->map(function (FiscalYear $year): array {
            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100"><a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main AjaxModal" data-ajax-modal="'.e(route('fiscal-years.editModal', $year)).'" data-size="lg" data-onsuccess="FiscalYearsIndex.onSaved" title="Edit"><iconify-icon icon="lucide:edit"></iconify-icon></a>';
            if (! $year->is_active) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-fiscal-year-delete" data-url="'.e(route('fiscal-years.destroy', $year)).'" title="Delete"><iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
            }

            return [$year->id, '<strong>'.e($year->name).'</strong>', $year->start_date->format('d M Y'), $year->end_date->format('d M Y'), $year->is_active ? '<span class="badge bg-dark-success-gradient text-white">Active</span>' : '<span class="badge bg-dark-warning-gradient text-white">Inactive</span>', $actions.'</div>'];
        })->all();

        return response()->json(['draw' => $draw, 'iTotalRecords' => $total, 'iTotalDisplayRecords' => $filtered, 'aaData' => $data]);
    }

    public function createModal(): View
    {
        return view('backend.modules.fiscal_years.create_modal');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->assertNoOverlap($data['start_date'], $data['end_date']);
        $year = DB::transaction(function () use ($data): FiscalYear {
            if ($data['is_active']) {
                $this->assertActiveFiscalYearCanBeChanged();
                FiscalYear::query()->where('is_active', true)->update(['is_active' => false]);
            }

            return FiscalYear::query()->create($data);
        }, 3);

        return response()->json(['ok' => true, 'msg' => 'Fiscal year created.', 'id' => $year->id]);
    }

    public function editModal(FiscalYear $fiscalYear): View
    {
        return view('backend.modules.fiscal_years.edit_modal', compact('fiscalYear'));
    }

    public function update(Request $request, FiscalYear $fiscalYear): JsonResponse
    {
        $data = $this->validated($request, $fiscalYear);
        $this->assertNoOverlap($data['start_date'], $data['end_date'], $fiscalYear->id);
        DB::transaction(function () use ($data, $fiscalYear): void {
            if ($data['is_active']) {
                $this->assertActiveFiscalYearCanBeChanged($fiscalYear->id);
                FiscalYear::query()->whereKeyNot($fiscalYear->id)->where('is_active', true)->update(['is_active' => false]);
            } elseif ($fiscalYear->is_active && ! FiscalYear::query()->whereKeyNot($fiscalYear->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Activate another fiscal year before deactivating the current one.']);
            }
            $fiscalYear->update($data);
        }, 3);

        return response()->json(['ok' => true, 'msg' => 'Fiscal year updated.']);
    }

    public function destroy(FiscalYear $fiscalYear): JsonResponse
    {
        if ($fiscalYear->is_active) {
            return response()->json(['ok' => false, 'msg' => 'The active fiscal year cannot be deleted.'], 422);
        }
        if (DB::table('journal_entries')->where('fiscal_year_id', $fiscalYear->id)->exists()
            || DB::table('account_opening_balances')->where('fiscal_year_id', $fiscalYear->id)->exists()
            || DB::table('finance_fiscal_year_closings')->where('fiscal_year_id', $fiscalYear->id)->exists()) {
            return response()->json(['ok' => false, 'msg' => 'This fiscal year is referenced by finance records.'], 422);
        }
        $fiscalYear->delete();

        return response()->json(['ok' => true, 'msg' => 'Fiscal year deleted.']);
    }

    public function select2(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $items = FiscalYear::query()->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderByDesc('id')->limit(20)->get(['id', 'name']);

        return response()->json(['results' => $items->map(fn (FiscalYear $year) => ['id' => $year->id, 'text' => $year->name])]);
    }

    /** @return array{name: string, start_date: string, end_date: string, is_active: bool} */
    private function validated(Request $request, ?FiscalYear $year = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('fiscal_years', 'name')->ignore($year?->id)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function assertNoOverlap(string $startDate, string $endDate, ?int $ignoreId = null): void
    {
        $overlap = FiscalYear::query()->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('start_date', '<=', $endDate)->where('end_date', '>=', $startDate)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => 'Fiscal-year date ranges cannot overlap.']);
        }
    }

    private function assertActiveFiscalYearCanBeChanged(?int $nextActiveYearId = null): void
    {
        $activeYearIds = DB::table('fiscal_years')
            ->where('is_active', true)
            ->when($nextActiveYearId, fn ($query, int $id) => $query->where('id', '!=', $id))
            ->pluck('id');

        if ($activeYearIds->isEmpty()) {
            return;
        }

        $hasUnpostedJournal = DB::table('journal_entries')
            ->whereIn('fiscal_year_id', $activeYearIds)
            ->where('status', 'DRAFT')
            ->exists();
        if ($hasUnpostedJournal) {
            throw ValidationException::withMessages([
                'is_active' => 'Post or void every Draft journal in the current fiscal year before activating another fiscal year.',
            ]);
        }

        $hasDraftClosing = DB::table('finance_fiscal_year_closings')
            ->whereIn('fiscal_year_id', $activeYearIds)
            ->where('status', 'DRAFT')
            ->exists();
        if ($hasDraftClosing) {
            throw ValidationException::withMessages([
                'is_active' => 'Prepare and post, or void, every Draft Fiscal Year Closing before activating another fiscal year.',
            ]);
        }

        $hasUnpostedClosing = DB::table('finance_fiscal_year_closings as closing')
            ->leftJoin('journal_entries as journal', function ($join): void {
                $join->on('journal.source_id', '=', 'closing.id')
                    ->where('journal.source_type', '=', 'fiscal_year_closing');
            })
            ->whereIn('closing.fiscal_year_id', $activeYearIds)
            ->where('closing.status', 'POSTED')
            ->where(function ($query): void {
                $query->whereNull('journal.id')->orWhere('journal.status', '!=', 'POSTED');
            })
            ->exists();

        if ($hasUnpostedClosing) {
            throw ValidationException::withMessages([
                'is_active' => 'Approve and post every prepared Fiscal Year Closing journal before activating the next fiscal year.',
            ]);
        }
    }
}
