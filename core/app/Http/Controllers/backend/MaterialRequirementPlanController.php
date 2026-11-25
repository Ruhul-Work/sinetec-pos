<?php

namespace App\Http\Controllers\backend;

use App\Enums\Reporting\DemandPlanStatus;
use App\Enums\Reporting\MrpStatus;
use App\Exceptions\Reporting\InvalidMrpException;
use App\Http\Controllers\Controller;
use App\Models\backend\Branch;
use App\Models\Reporting\DemandPlan;
use App\Models\Reporting\MaterialRequirementPlan;
use App\Services\Reporting\MaterialRequirementPlanService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaterialRequirementPlanController extends Controller
{
    public function __construct(private readonly MaterialRequirementPlanService $mrpService) {}

    public function index(): View
    {
        return view('backend.modules.material_requirement_plan.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = [
            'material_requirement_plans.id',
            'material_requirement_plans.plan_no',
            'demand_plan.plan_no',
            'period.year',
            'branch.name',
            'fp_lines_count',
            'comp_lines_count',
            'material_requirement_plans.status',
            'material_requirement_plans.approved_at',
            null,
        ];

        $draw = $request->integer('draw');
        $start = max($request->integer('start'), 0);
        $requestedLength = $request->integer('length', 10);
        $length = $requestedLength === -1 ? 1000 : min(max($requestedLength, 1), 1000);
        $search = trim((string) $request->input('search.value', ''));
        $status = strtoupper(trim((string) $request->input('status', '')));
        $orderColumn = (int) $request->input('order.0.column', 0);
        $orderBy = $columns[$orderColumn] ?? 'material_requirement_plans.id';
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = MaterialRequirementPlan::query()
            ->select('material_requirement_plans.*')
            ->join('branches as branch', 'branch.id', '=', 'material_requirement_plans.branch_id')
            ->join('reporting_periods as period', 'period.id', '=', 'material_requirement_plans.reporting_period_id')
            ->join('demand_plans as demand_plan', 'demand_plan.id', '=', 'material_requirement_plans.demand_plan_id')
            ->with(['branch', 'period', 'demandPlan', 'approver'])
            ->withCount([
                'finishedProductLines as fp_lines_count',
                'componentLines as comp_lines_count',
            ]);

        $this->applyBranchScope($query, $request);
        $recordsTotal = (clone $query)->count('material_requirement_plans.id');

        if (in_array($status, array_column(MrpStatus::cases(), 'value'), true)) {
            $query->where('material_requirement_plans.status', $status);
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $like = "%{$search}%";
                $builder
                    ->where('material_requirement_plans.plan_no', 'like', $like)
                    ->orWhere('demand_plan.plan_no', 'like', $like)
                    ->orWhere('material_requirement_plans.status', 'like', $like)
                    ->orWhere('branch.name', 'like', $like)
                    ->orWhere('branch.code', 'like', $like)
                    ->orWhere('period.year', 'like', $like)
                    ->orWhere('period.month', 'like', $like);
            });
        }

        $recordsFiltered = (clone $query)->count('material_requirement_plans.id');

        if ($orderColumn === 3) {
            $query->orderBy('period.year', $orderDirection)
                ->orderBy('period.month', $orderDirection);
        } else {
            $query->orderBy($orderBy, $orderDirection);
        }

        $plans = $query->skip($start)->take($length)->get();
        $data = $plans->map(fn (MaterialRequirementPlan $plan): array => [
            $plan->id,
            '<span class="fw-semibold">'.e($plan->plan_no).'</span>',
            '<a href="'.route('planning.demand-plans.show', $plan->demandPlan).'" class="hover-text-primary fw-medium">'.e($plan->demandPlan->plan_no).'</a>',
            '<span class="fw-semibold">'.sprintf('%04d-%02d', $plan->period->year, $plan->period->month).'</span>',
            '<span class="fw-medium">'.e($plan->branch->name).'</span><br><code>'.e($plan->branch->code).'</code>',
            '<span class="badge bg-primary-focus text-primary-600 px-10 py-6 radius-6">'.e((string) $plan->fp_lines_count).' products</span>',
            '<span class="badge bg-info-focus text-info-600 px-10 py-6 radius-6">'.e((string) $plan->comp_lines_count).' items</span>',
            '<span class="badge text-sm fw-semibold px-12 py-7 radius-4 '.e($plan->status->badgeClass()).'">'.e($plan->status->label()).'</span>',
            $plan->approved_at
                ? e($plan->approved_at->format('d M Y, h:i A')).'<small class="d-block text-muted">'.e($plan->approver?->name ?: 'System').'</small>'
                : '<span class="text-muted">&mdash;</span>',
            view('backend.modules.material_requirement_plan.partials.action', [
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
        $eligibleDemandPlans = DemandPlan::query()
            ->with(['branch', 'period'])
            ->withCount('items')
            ->where('status', DemandPlanStatus::Approved)
            ->whereDoesntHave('materialRequirementPlan')
            ->when(current_branch_id(), fn (Builder $q, int $bId) => $q->where('branch_id', $bId))
            ->when(! $request->user()?->isSuper() && $request->user()?->branch_id, fn (Builder $q) => $q->where('branch_id', (int) $request->user()->branch_id))
            ->orderByDesc('id')
            ->get();

        $selectedDemandPlanId = $request->integer('demand_plan_id') ?: null;

        return view('backend.modules.material_requirement_plan.create', [
            'demandPlans' => $eligibleDemandPlans,
            'selectedDemandPlanId' => $selectedDemandPlanId,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'demand_plan_id' => ['required', 'integer', 'exists:demand_plans,id'],
        ]);

        $demandPlan = DemandPlan::query()->findOrFail($request->integer('demand_plan_id'));
        $this->assertBranchAccess($request, $demandPlan->branch);

        try {
            $mrp = $this->mrpService->generate($demandPlan, (int) $request->user()->id);
        } catch (InvalidMrpException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('planning.material-requirement-plans.show', $mrp)
            ->with('success', 'Material Requirement Plan Draft generated successfully.');
    }

    public function generateFromDemand(Request $request, DemandPlan $demandPlan): RedirectResponse
    {
        $this->assertBranchAccess($request, $demandPlan->branch);

        try {
            $mrp = $this->mrpService->generate($demandPlan, (int) $request->user()->id);
        } catch (InvalidMrpException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('planning.material-requirement-plans.show', $mrp)
            ->with('success', 'Material Requirement Plan generated from Demand Plan.');
    }

    public function show(Request $request, MaterialRequirementPlan $materialRequirementPlan): View
    {
        $this->assertPlanAccess($request, $materialRequirementPlan);

        $materialRequirementPlan->load([
            'demandPlan.branch',
            'demandPlan.period',
            'branch',
            'period',
            'generator',
            'approver',
            'finishedProductLines.finishedProduct.baseUnit',
            'componentLines.rawMaterial.baseUnit',
            'componentLines.part.baseUnit',
            'purchaseOrders.supplier',
        ]);

        $recommendations = $this->mrpService->purchaseRecommendations($materialRequirementPlan);

        return view('backend.modules.material_requirement_plan.show', [
            'plan' => $materialRequirementPlan,
            'recommendations' => $recommendations,
        ]);
    }

    public function approve(Request $request, MaterialRequirementPlan $materialRequirementPlan): RedirectResponse
    {
        $this->assertPlanAccess($request, $materialRequirementPlan);

        try {
            $this->mrpService->approve($materialRequirementPlan, (int) $request->user()->id);
        } catch (InvalidMrpException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Material Requirement Plan approved.');
    }

    public function cancel(Request $request, MaterialRequirementPlan $materialRequirementPlan): RedirectResponse
    {
        $this->assertPlanAccess($request, $materialRequirementPlan);

        try {
            $this->mrpService->cancel($materialRequirementPlan);
        } catch (InvalidMrpException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Material Requirement Plan cancelled.');
    }

    public function destroy(Request $request, MaterialRequirementPlan $materialRequirementPlan): JsonResponse
    {
        $this->assertPlanAccess($request, $materialRequirementPlan);

        try {
            $this->mrpService->delete($materialRequirementPlan);
        } catch (InvalidMrpException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Material Requirement Plan deleted.']);
    }

    private function applyBranchScope(Builder $query, Request $request): void
    {
        $branchId = current_branch_id();
        if ($branchId === null && ! $request->user()?->isSuper()) {
            $branchId = (int) $request->user()?->branch_id;
        }

        if ($branchId) {
            $query->where('material_requirement_plans.branch_id', $branchId);
        }
    }

    private function assertPlanAccess(Request $request, MaterialRequirementPlan $plan): void
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
}
