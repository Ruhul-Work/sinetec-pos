<?php

namespace App\Http\Controllers\backend;

use App\Enums\Reporting\MonthlyRateSheetStatus;
use App\Exceptions\Reporting\InvalidMonthlyRateSheetException;
use App\Http\Controllers\Controller;
use App\Models\Reporting\MonthlyRateSheet;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MonthlyRateSheetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MonthlyRateSheetController extends Controller
{
    public function __construct(private readonly MonthlyRateSheetService $rateSheets) {}

    public function index(): View
    {
        $periods = ReportingPeriod::query()
            ->with(['events'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();
        $sheets = MonthlyRateSheet::query()
            ->with('period')
            ->orderByDesc('id')
            ->get();

        return view('backend.modules.monthly_rate_sheet.index', compact('periods', 'sheets'));
    }

    public function create(ReportingPeriod $period): RedirectResponse|View
    {
        try {
            $sheet = $this->rateSheets->create($period, (int) request()->user()->id);
        } catch (InvalidMonthlyRateSheetException $exception) {
            return redirect()->route('reporting.rate-sheets.index')->with('error', $exception->getMessage());
        }

        return redirect()->route('reporting.rate-sheets.show', $sheet);
    }

    public function show(MonthlyRateSheet $sheet): View
    {
        $sheet->load([
            'period',
            'creator',
            'approver',
            'rawMaterialRates.item.baseUnit',
            'partRates.item.baseUnit',
            'finishedProductRates.item.baseUnit',
        ]);

        return view('backend.modules.monthly_rate_sheet.show', compact('sheet'));
    }

    public function update(MonthlyRateSheet $sheet, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:5000'],
            'raw_material_rates' => ['required', 'array'],
            'raw_material_rates.*' => ['required', 'numeric', 'min:0'],
            'part_rates' => ['required', 'array'],
            'part_rates.*' => ['required', 'numeric', 'min:0'],
            'finished_product_rates' => ['required', 'array'],
            'finished_product_rates.*' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $this->rateSheets->save($sheet, $data, (int) $request->user()->id);
        } catch (InvalidMonthlyRateSheetException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Monthly Rate Sheet saved.');
    }

    public function approve(MonthlyRateSheet $sheet, Request $request): RedirectResponse
    {
        try {
            $this->rateSheets->approve($sheet, (int) $request->user()->id);
        } catch (InvalidMonthlyRateSheetException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Monthly Rate Sheet approved.');
    }

    public function lock(MonthlyRateSheet $sheet, Request $request): RedirectResponse
    {
        try {
            $this->rateSheets->lock($sheet, (int) $request->user()->id);
        } catch (InvalidMonthlyRateSheetException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Monthly Rate Sheet locked.');
    }
}
