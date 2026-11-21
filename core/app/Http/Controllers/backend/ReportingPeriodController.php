<?php
namespace App\Http\Controllers\backend;

use App\Exceptions\Reporting\InvalidReportingPeriodException;
use App\Http\Controllers\Controller;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\ReportingPeriodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportingPeriodController extends Controller
{
    public function __construct(private readonly ReportingPeriodService $periods)
    {}

    public function index(): View
    {
        $periods = ReportingPeriod::query()
            ->with(['closedBy', 'events'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        return view('backend.modules.reporting_periods.index', compact('periods'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year'  => ['required', 'integer', 'between:2000,9999'],
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
