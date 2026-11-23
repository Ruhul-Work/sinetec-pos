<?php

namespace App\Http\Controllers\backend;

use App\Enums\Reporting\MonthlyRateSheetStatus;
use App\Enums\Reporting\MonthlyReportSnapshotStatus;
use App\Enums\Reporting\ReportingPeriodStatus;
use App\Exceptions\Reporting\InvalidMonthlyReportSnapshotException;
use App\Http\Controllers\Controller;
use App\Models\backend\Branch;
use App\Models\Reporting\MonthlyRateSheet;
use App\Models\Reporting\MonthlyReportSnapshot;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MonthlyReportSnapshotDataTableService;
use App\Services\Reporting\MonthlyReportSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MonthlyReportSnapshotController extends Controller
{
    public function __construct(
        private readonly MonthlyReportSnapshotService $snapshots,
        private readonly MonthlyReportSnapshotDataTableService $dataTables,
    ) {}

    public function index(Request $request): View
    {
        $scopeBranchId = $this->scopeBranchId($request);
        $scopeKey = MonthlyReportSnapshot::scopeKey($scopeBranchId);
        $periods = $this->periodsForScope($scopeKey);
        $rateSheets = MonthlyRateSheet::query()
            ->get()
            ->keyBy('reporting_period_id');
        $scopeBranch = $scopeBranchId
            ? Branch::query()->findOrFail($scopeBranchId)
            : null;
        $scopeLabel = $scopeBranch
            ? $scopeBranch->name.' ('.$scopeBranch->code.')'
            : 'All Branches / Company-wide';
        $registerRows = $periods->map(
            fn (ReportingPeriod $period): array => $this->registerRow(
                $period,
                $rateSheets->get($period->id),
            ),
        );

        return view('backend.modules.monthly_report_snapshot.index', compact(
            'registerRows',
            'scopeBranch',
            'scopeLabel',
        ));
    }

    public function listAjax(Request $request): JsonResponse
    {
        $scopeBranchId = $this->scopeBranchId($request);
        $scopeKey = MonthlyReportSnapshot::scopeKey($scopeBranchId);
        $periods = $this->periodsForScope($scopeKey);
        $rateSheets = MonthlyRateSheet::query()
            ->get()
            ->keyBy('reporting_period_id');
        $registerRows = $periods->map(
            fn (ReportingPeriod $period): array => $this->registerRow(
                $period,
                $rateSheets->get($period->id),
            ),
        );

        return $this->dataTables->registerResponse($request, $registerRows);
    }

    public function store(ReportingPeriod $period, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $snapshot = $this->snapshots->generate(
                $period,
                (int) $request->user()->id,
                $data['note'] ?? null,
                $this->scopeBranchId($request),
            );
        } catch (InvalidMonthlyReportSnapshotException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('reporting.snapshots.show', $snapshot)
            ->with('success', 'Final Monthly Snapshot generated successfully.');
    }

    public function show(MonthlyReportSnapshot $snapshot, Request $request): View
    {
        abort_unless($snapshot->report_type === MonthlyReportSnapshot::REPORT_TYPE, 404);
        $this->ensureSnapshotAccess($request, $snapshot);

        $snapshot->load(['period', 'rateSheet', 'branch', 'generator', 'supersedes']);
        $filters = $this->snapshotFilters($snapshot, $request);
        $branches = $filters['branches'];
        $availableWarehouses = $filters['available_warehouses'];
        $selectedBranchId = $filters['branch_id'];
        $selectedWarehouseId = $filters['warehouse_id'];
        $fixedBranchId = $filters['fixed_branch_id'];

        $report = $this->snapshots->presentation(
            $snapshot,
            $selectedBranchId,
            $selectedWarehouseId,
        );
        $history = MonthlyReportSnapshot::query()
            ->with('generator')
            ->where('reporting_period_id', $snapshot->reporting_period_id)
            ->where('report_type', MonthlyReportSnapshot::REPORT_TYPE)
            ->where('scope_key', $snapshot->scope_key)
            ->orderByDesc('version')
            ->get();
        $isCurrent = $snapshot->status === MonthlyReportSnapshotStatus::Current;
        $checksumValid = $this->snapshots->hasValidChecksum($snapshot);

        return view('backend.modules.monthly_report_snapshot.show', compact(
            'snapshot',
            'report',
            'branches',
            'availableWarehouses',
            'selectedBranchId',
            'selectedWarehouseId',
            'fixedBranchId',
            'history',
            'isCurrent',
            'checksumValid',
        ));
    }

    public function tableAjax(
        MonthlyReportSnapshot $snapshot,
        Request $request,
    ): JsonResponse {
        abort_unless($snapshot->report_type === MonthlyReportSnapshot::REPORT_TYPE, 404);
        $this->ensureSnapshotAccess($request, $snapshot);
        $data = $request->validate([
            'section' => [
                'required',
                Rule::in([
                    'valuation',
                    'purchase',
                    'stock',
                    'part-production',
                    'assembly',
                    'sales',
                ]),
            ],
        ]);
        $filters = $this->snapshotFilters($snapshot, $request);
        $report = $this->snapshots->presentation(
            $snapshot,
            $filters['branch_id'],
            $filters['warehouse_id'],
        );

        return $this->dataTables->reportResponse(
            $request,
            $data['section'],
            $report,
        );
    }

    private function positiveId(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /** @return Collection<int, ReportingPeriod> */
    private function periodsForScope(string $scopeKey): Collection
    {
        return ReportingPeriod::query()
            ->with([
                'events',
                'snapshots' => fn ($query) => $query->where('scope_key', $scopeKey),
                'snapshots.generator',
                'snapshots.rateSheet',
            ])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();
    }

    /** @return array<string, mixed> */
    private function snapshotFilters(
        MonthlyReportSnapshot $snapshot,
        Request $request,
    ): array {
        $dimensions = $snapshot->payload['dimensions'] ?? [];
        $branches = collect($dimensions['branches'] ?? []);
        $warehouses = collect($dimensions['warehouses'] ?? []);
        $fixedBranchId = $snapshot->branch_id ?: current_branch_id();
        $selectedBranchId = $fixedBranchId ?: $this->positiveId($request->input('branch_id'));

        if ($selectedBranchId && ! $branches->contains('id', $selectedBranchId)) {
            $selectedBranchId = $fixedBranchId;
        }

        $availableWarehouses = $warehouses
            ->when(
                $selectedBranchId,
                fn ($rows) => $rows->where('branch_id', $selectedBranchId),
            )
            ->values();
        $selectedWarehouseId = $this->positiveId($request->input('warehouse_id'));

        if ($selectedWarehouseId && ! $availableWarehouses->contains('id', $selectedWarehouseId)) {
            $selectedWarehouseId = null;
        }

        return [
            'branches' => $branches,
            'available_warehouses' => $availableWarehouses,
            'fixed_branch_id' => $fixedBranchId,
            'branch_id' => $selectedBranchId,
            'warehouse_id' => $selectedWarehouseId,
        ];
    }

    /** @return array<string, mixed> */
    private function registerRow(
        ReportingPeriod $period,
        ?MonthlyRateSheet $rateSheet,
    ): array {
        $packageSnapshots = $period->snapshots->where(
            'report_type',
            MonthlyReportSnapshot::REPORT_TYPE,
        );
        $currentSnapshot = $packageSnapshots->first(
            fn (MonthlyReportSnapshot $snapshot): bool => $snapshot->status === MonthlyReportSnapshotStatus::Current,
        );
        $reopenedAt = $currentSnapshot
            ? $period->events
                ->where('action', 'REOPENED')
                ->where('created_at', '>', $currentSnapshot->generated_at)
                ->max('created_at')
            : null;
        $closedAgain = $reopenedAt
            ? $period->events
                ->where('action', 'CLOSED')
                ->where('created_at', '>', $reopenedAt)
                ->isNotEmpty()
            : false;

        return [
            'period' => $period,
            'rate_sheet' => $rateSheet,
            'current_snapshot' => $currentSnapshot,
            'version_count' => $packageSnapshots->count(),
            'can_create' => $period->status === ReportingPeriodStatus::Closed
                && $rateSheet?->status === MonthlyRateSheetStatus::Locked
                && (! $currentSnapshot || $closedAgain),
            'next_action' => $this->nextAction(
                $period,
                $rateSheet,
                $currentSnapshot,
                $closedAgain,
            ),
            'next_route' => $this->nextRoute($period, $rateSheet, $currentSnapshot),
        ];
    }

    private function scopeBranchId(Request $request): ?int
    {
        $user = $request->user();
        abort_unless($user, 403);

        if (! $user->isSuper()) {
            $branchId = (int) $user->branch_id;

            abort_unless(
                $branchId > 0,
                403,
                'Assign a branch to this user before using Monthly Snapshots.',
            );

            return $branchId;
        }

        return current_branch_id();
    }

    private function ensureSnapshotAccess(
        Request $request,
        MonthlyReportSnapshot $snapshot,
    ): void {
        $user = $request->user();
        abort_unless($user, 403);

        if ($user->isSuper()) {
            return;
        }

        abort_unless(
            $snapshot->branch_id !== null
                && (int) $snapshot->branch_id === (int) $user->branch_id,
            403,
            'You cannot access another branch or company-wide snapshot.',
        );
    }

    private function nextAction(
        ReportingPeriod $period,
        ?MonthlyRateSheet $rateSheet,
        ?MonthlyReportSnapshot $currentSnapshot,
        bool $closedAgain,
    ): string {
        if (! $rateSheet) {
            return 'Prepare and lock the Monthly Rate Sheet first.';
        }
        if ($rateSheet->status !== MonthlyRateSheetStatus::Locked) {
            return 'Approve and lock the Monthly Rate Sheet first.';
        }
        if ($period->status !== ReportingPeriodStatus::Closed) {
            if (now()->toDateString() <= $period->ends_on->toDateString()) {
                return 'Period can be closed after '.$period->ends_on->format('d M Y').'.';
            }

            return 'Close the Reporting Period first.';
        }
        if ($currentSnapshot && ! $closedAgain) {
            return 'Current snapshot is already final.';
        }

        return 'Ready to generate.';
    }

    private function nextRoute(
        ReportingPeriod $period,
        ?MonthlyRateSheet $rateSheet,
        ?MonthlyReportSnapshot $currentSnapshot,
    ): ?string {
        if ($currentSnapshot) {
            return null;
        }

        if (! $rateSheet || $rateSheet->status !== MonthlyRateSheetStatus::Locked) {
            return route('reporting.rate-sheets.index');
        }

        if ($period->status !== ReportingPeriodStatus::Closed) {
            return route('reporting.periods.index');
        }

        return null;
    }
}
