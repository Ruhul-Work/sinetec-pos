<?php

namespace App\Http\Controllers\backend;

use App\Enums\Reporting\DemandPlanStatus;
use App\Enums\Reporting\ReportingPeriodStatus;
use App\Exceptions\Reporting\InvalidDemandPlanException;
use App\Http\Controllers\Controller;
use App\Models\backend\Branch;
use App\Models\Reporting\DemandPlan;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\DemandPlanService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DemandPlanController extends Controller
{
    public function __construct(private readonly DemandPlanService $demandPlans) {}

    public function index(): View
    {
        return view('backend.modules.demand_plan.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = [
            'demand_plans.id',
            'demand_plans.plan_no',
            'period.year',
            'branch.name',
            'items_count',
            'items_sum_forecast_quantity',
            'demand_plans.status',
            'demand_plans.approved_at',
            null,
        ];
        $draw = $request->integer('draw');
        $start = max($request->integer('start'), 0);
        $requestedLength = $request->integer('length', 10);
        $length = $requestedLength === -1 ? 1000 : min(max($requestedLength, 1), 1000);
        $search = trim((string) $request->input('search.value', ''));
        $status = strtoupper(trim((string) $request->input('status', '')));
        $orderColumn = (int) $request->input('order.0.column', 0);
        $orderBy = $columns[$orderColumn] ?? 'demand_plans.id';
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc'
            ? 'asc'
            : 'desc';

        $query = DemandPlan::query()
            ->select('demand_plans.*')
            ->join('branches as branch', 'branch.id', '=', 'demand_plans.branch_id')
            ->join('reporting_periods as period', 'period.id', '=', 'demand_plans.reporting_period_id')
            ->with(['branch', 'period', 'approver'])
            ->withCount('items')
            ->withSum('items', 'forecast_quantity');
        $this->applyBranchScope($query, $request);
        $recordsTotal = (clone $query)->count('demand_plans.id');

        if (in_array($status, array_column(DemandPlanStatus::cases(), 'value'), true)) {
            $query->where('demand_plans.status', $status);
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $like = "%{$search}%";
                $builder
                    ->where('demand_plans.plan_no', 'like', $like)
                    ->orWhere('demand_plans.status', 'like', $like)
                    ->orWhere('branch.name', 'like', $like)
                    ->orWhere('branch.code', 'like', $like)
                    ->orWhere('period.year', 'like', $like)
                    ->orWhere('period.month', 'like', $like);
            });
        }

        $recordsFiltered = (clone $query)->count('demand_plans.id');

        if ($orderColumn === 2) {
            $query->orderBy('period.year', $orderDirection)
                ->orderBy('period.month', $orderDirection);
        } else {
            $query->orderBy($orderBy, $orderDirection);
        }

        $plans = $query->skip($start)->take($length)->get();
        $data = $plans->map(fn (DemandPlan $plan): array => [
            $plan->id,
            '<span class="fw-semibold">'.e($plan->plan_no).'</span>',
            '<span class="fw-semibold">'.sprintf('%04d-%02d', $plan->period->year, $plan->period->month).'</span>',
            '<span class="fw-medium">'.e($plan->branch->name).'</span><br>'
                .'<code>'.e($plan->branch->code).'</code>',
            '<span class="badge bg-primary-focus text-primary-600 px-10 py-6 radius-6">'
                .e((string) $plan->items_count).'</span>',
            '<span class="d-block text-end fw-semibold">'
                .e($this->formatQuantity($plan->items_sum_forecast_quantity ?? 0)).'</span>',
            '<span class="badge text-sm fw-semibold px-12 py-7 radius-4 '
                .e($plan->status->badgeClass()).'">'.e($plan->status->label()).'</span>',
            $plan->approved_at
                ? e($plan->approved_at->format('d M Y, h:i A'))
                    .'<small class="d-block text-muted">'.e($plan->approver?->name ?: 'System').'</small>'
                : '<span class="text-muted">&mdash;</span>',
            view('backend.modules.demand_plan.partials.action', [
                'plan' => $plan,
                'user' => $request->user(),
            ])->render(),
        ])->all();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
            'iTotalRecords' => $recordsTotal,
            'iTotalDisplayRecords' => $recordsFiltered,
            'aaData' => $data,
        ]);
    }

    public function create(Request $request): View
    {
        $branches = $this->availableBranches($request);
        $periods = $this->openPeriods();
        $branch = $this->selectedBranch($request, $branches);
        $period = $this->selectedPeriod($request, $periods);
        $historyMonths = $this->selectedHistoryMonths($request);
        $suggestions = $branch && $period
            ? $this->demandPlans->suggestions($branch, $period, $historyMonths)
            : collect();

        return view('backend.modules.demand_plan.form', [
            'title' => 'Create Demand Plan',
            'action' => route('planning.demand-plans.store'),
            'method' => 'POST',
            'plan' => new DemandPlan([
                'branch_id' => $branch?->id,
                'reporting_period_id' => $period?->id,
                'history_months' => $historyMonths,
            ]),
            'branches' => $branches,
            'periods' => $periods,
            'suggestions' => $suggestions,
            'existingItems' => collect(),
            'canReloadSuggestions' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $this->assertBranchAccess($request, $branch);
        $period = ReportingPeriod::query()->findOrFail($data['reporting_period_id']);

        try {
            $plan = $this->demandPlans->createDraft(
                $branch,
                $period,
                (int) $data['history_months'],
                $data['items'],
                $data['note'] ?? null,
                (int) $request->user()->id,
            );
        } catch (InvalidDemandPlanException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('planning.demand-plans.show', $plan)
            ->with('success', 'Demand Plan Draft created. Review it before approval.');
    }

    public function show(Request $request, DemandPlan $demandPlan): View
    {
        $this->assertPlanAccess($request, $demandPlan);
        $demandPlan->load([
            'branch',
            'period',
            'items.finishedProduct.baseUnit',
            'creator',
            'approver',
            'materialRequirementPlan',
        ]);

        return view('backend.modules.demand_plan.show', ['plan' => $demandPlan]);
    }

    public function edit(Request $request, DemandPlan $demandPlan): View
    {
        $this->assertPlanAccess($request, $demandPlan);
        abort_unless(
            $demandPlan->status === DemandPlanStatus::Draft,
            422,
            'Only a Draft Demand Plan can be edited.',
        );

        $demandPlan->load(['branch', 'period', 'items.finishedProduct.baseUnit']);
        $suggestions = $this->demandPlans->suggestions(
            $demandPlan->branch,
            $demandPlan->period,
            (int) $demandPlan->history_months,
        );

        return view('backend.modules.demand_plan.form', [
            'title' => 'Edit Demand Plan',
            'action' => route('planning.demand-plans.update', $demandPlan),
            'method' => 'PUT',
            'plan' => $demandPlan,
            'branches' => collect([$demandPlan->branch]),
            'periods' => collect([$demandPlan->period]),
            'suggestions' => $suggestions,
            'existingItems' => $demandPlan->items->keyBy('finished_product_id'),
            'canReloadSuggestions' => false,
        ]);
    }

    public function update(Request $request, DemandPlan $demandPlan): RedirectResponse
    {
        $this->assertPlanAccess($request, $demandPlan);
        $data = $this->validated($request, false);

        try {
            $plan = $this->demandPlans->updateDraft(
                $demandPlan,
                (int) $data['history_months'],
                $data['items'],
                $data['note'] ?? null,
            );
        } catch (InvalidDemandPlanException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('planning.demand-plans.show', $plan)
            ->with('success', 'Demand Plan Draft updated.');
    }

    public function approve(Request $request, DemandPlan $demandPlan): RedirectResponse
    {
        $this->assertPlanAccess($request, $demandPlan);

        try {
            $this->demandPlans->approve($demandPlan, (int) $request->user()->id);
        } catch (InvalidDemandPlanException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Demand Plan approved and frozen for Material Requirement Planning.');
    }

    public function cancel(Request $request, DemandPlan $demandPlan): RedirectResponse
    {
        $this->assertPlanAccess($request, $demandPlan);

        try {
            $this->demandPlans->cancel($demandPlan);
        } catch (InvalidDemandPlanException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Demand Plan cancelled.');
    }

    public function destroy(Request $request, DemandPlan $demandPlan): JsonResponse
    {
        $this->assertPlanAccess($request, $demandPlan);

        try {
            $this->demandPlans->delete($demandPlan);
        } catch (InvalidDemandPlanException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Demand Plan deleted.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $includeScope = true): array
    {
        $rules = [
            'history_months' => ['required', 'integer', Rule::in([1, 3, 6, 12])],
            'note' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.forecast_quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999999999.999999'],
            'items.*.safety_quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999999999.999999'],
            'items.*.basis' => ['nullable', 'string', 'max:1000'],
        ];

        if ($includeScope) {
            $rules['branch_id'] = [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where(
                    fn ($query) => $query->whereNull('deleted_at')->where('is_active', true),
                ),
            ];
            $rules['reporting_period_id'] = [
                'required',
                'integer',
                Rule::exists('reporting_periods', 'id')->where('status', ReportingPeriodStatus::Open->value),
            ];
        }

        return $request->validate($rules);
    }

    private function applyBranchScope(Builder $query, Request $request): void
    {
        $branchId = current_branch_id();
        if ($branchId === null && ! $request->user()?->isSuper()) {
            $branchId = (int) $request->user()?->branch_id;
        }

        if ($branchId) {
            $query->where('demand_plans.branch_id', $branchId);
        }
    }

    private function availableBranches(Request $request): Collection
    {
        $query = Branch::query()->where('is_active', true)->orderBy('name');
        $branchId = current_branch_id();

        if ($branchId !== null) {
            $query->whereKey($branchId);
        } elseif (! $request->user()?->isSuper()) {
            $query->whereKey((int) $request->user()?->branch_id);
        }

        return $query->get(['id', 'name', 'code']);
    }

    private function openPeriods(): Collection
    {
        return ReportingPeriod::query()
            ->where('status', ReportingPeriodStatus::Open)
            ->orderBy('starts_on')
            ->get();
    }

    private function selectedBranch(Request $request, Collection $branches): ?Branch
    {
        $requestedId = (int) ($request->old('branch_id') ?: $request->integer('branch_id'));

        return $branches->firstWhere('id', $requestedId) ?? $branches->first();
    }

    private function selectedPeriod(Request $request, Collection $periods): ?ReportingPeriod
    {
        $requestedId = (int) (
            $request->old('reporting_period_id')
            ?: $request->integer('reporting_period_id')
        );
        if ($requestedId && $period = $periods->firstWhere('id', $requestedId)) {
            return $period;
        }

        $nextMonth = today()->addMonthNoOverflow()->startOfMonth();
        $currentMonth = today()->startOfMonth();

        return $periods->first(fn (ReportingPeriod $period): bool => $period->starts_on->gte($nextMonth))
            ?? $periods->first(fn (ReportingPeriod $period): bool => $period->starts_on->gte($currentMonth))
            ?? $periods->last();
    }

    private function selectedHistoryMonths(Request $request): int
    {
        $months = (int) ($request->old('history_months') ?: $request->integer('history_months', 3));

        return in_array($months, [1, 3, 6, 12], true) ? $months : 3;
    }

    private function assertPlanAccess(Request $request, DemandPlan $plan): void
    {
        $branch = $plan->relationLoaded('branch') ? $plan->branch : $plan->branch()->firstOrFail();
        $this->assertBranchAccess($request, $branch);
    }

    private function assertBranchAccess(Request $request, Branch $branch): void
    {
        $scopedBranchId = current_branch_id();
        if ($scopedBranchId !== null) {
            abort_unless($scopedBranchId === (int) $branch->id, 403);

            return;
        }

        if (! $request->user()?->isSuper()) {
            abort_unless((int) $request->user()?->branch_id === (int) $branch->id, 403);
        }
    }

    private function formatQuantity(mixed $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 3, '.', ','), '0'), '.');
    }
}
