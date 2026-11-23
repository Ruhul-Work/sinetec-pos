<?php

namespace App\Http\Controllers\backend;

use App\Exceptions\Reporting\InvalidReportingPeriodException;
use App\Http\Controllers\Controller;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\ReportingPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportingPeriodController extends Controller
{
    public function __construct(private readonly ReportingPeriodService $periods) {}

    public function index(): View
    {
        return view('backend.modules.reporting_periods.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = [
            'id',
            'year',
            'starts_on',
            'status',
            'closed_at',
            'updated_at',
            null,
        ];
        $draw = $request->integer('draw');
        $start = max($request->integer('start'), 0);
        $requestedLength = $request->integer('length', 10);
        $length = $requestedLength === -1
            ? 1000
            : min(max($requestedLength, 1), 1000);
        $search = trim((string) $request->input('search.value', ''));
        $orderColumn = (int) $request->input('order.0.column', 0);
        $orderBy = $columns[$orderColumn] ?? 'id';
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc'
            ? 'asc'
            : 'desc';

        $query = ReportingPeriod::query()->with([
            'closedBy',
            'events.actor',
        ]);
        $recordsTotal = (clone $query)->count();

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $like = '%'.$search.'%';

                $builder
                    ->where('status', 'like', $like)
                    ->orWhere('year', 'like', $like)
                    ->orWhere('month', 'like', $like)
                    ->orWhereHas('closedBy', fn ($userQuery) => $userQuery->where('name', 'like', $like))
                    ->orWhereHas('events.actor', fn ($userQuery) => $userQuery->where('name', 'like', $like));

                if (preg_match('/^(?<year>\d{4})-(?<month>\d{1,2})$/', $search, $periodMatch)) {
                    $builder->orWhere(function ($periodQuery) use ($periodMatch): void {
                        $periodQuery
                            ->where('year', (int) $periodMatch['year'])
                            ->where('month', (int) $periodMatch['month']);
                    });
                }
            });
        }

        $recordsFiltered = (clone $query)->count();

        if ($orderColumn === 1) {
            $query
                ->orderBy('year', $orderDirection)
                ->orderBy('month', $orderDirection);
        } else {
            $query->orderBy($orderBy, $orderDirection);
        }

        $periods = $query
            ->skip($start)
            ->take($length)
            ->get();
        $data = $periods->map(function (ReportingPeriod $period): array {
            $closeEvent = $period->events
                ->where('action', 'CLOSED')
                ->sortByDesc('id')
                ->first();

            return [
                $period->id,
                '<span class="fw-semibold">'.sprintf('%04d-%02d', $period->year, $period->month).'</span>',
                e($period->starts_on->format('d M Y'))
                    .' &ndash; '.e($period->ends_on->format('d M Y')),
                '<span class="badge text-sm fw-semibold px-12 py-7 radius-4 '
                    .e($period->status->badgeClass()).'">'
                    .e($period->status->label()).'</span>',
                view(
                    'backend.modules.reporting_periods.partials.closed_by',
                    compact('period', 'closeEvent'),
                )->render(),
                view(
                    'backend.modules.reporting_periods.partials.activity',
                    compact('period'),
                )->render(),
                view(
                    'backend.modules.reporting_periods.partials.action',
                    compact('period'),
                )->render(),
            ];
        })->all();

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

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,9999'],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);

        try {
            $this->periods->create(
                (int) $data['year'],
                (int) $data['month'],
                (int) $request->user()->id,
            );
        } catch (InvalidReportingPeriodException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Reporting period opened.');
    }

    public function close(ReportingPeriod $period, Request $request): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $this->periods->close($period, $data['reason'], (int) $request->user()->id);
        } catch (InvalidReportingPeriodException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Reporting period closed.');
    }

    public function reopen(ReportingPeriod $period, Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canDo('reporting.periods.index', 'view'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $this->periods->reopen($period, $data['reason'], (int) $request->user()->id);
        } catch (InvalidReportingPeriodException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Reporting period reopened. Its activity history has been retained.');
    }
}
