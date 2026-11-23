<?php

namespace App\Http\Controllers\backend;

use App\Exceptions\Reporting\InvalidMonthlyRateSheetException;
use App\Http\Controllers\Controller;
use App\Models\Reporting\MonthlyRateSheet;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MonthlyRateSheetService;
use Illuminate\Http\JsonResponse;
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

    public function listAjax(Request $request): JsonResponse
    {
        $columns = [
            'monthly_rate_sheets.id',
            'monthly_rate_sheets.sheet_no',
            'period.year',
            'monthly_rate_sheets.status',
            'monthly_rate_sheets.approved_at',
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
        $orderBy = $columns[$orderColumn] ?? 'monthly_rate_sheets.id';
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc'
            ? 'asc'
            : 'desc';

        $query = MonthlyRateSheet::query()
            ->select('monthly_rate_sheets.*')
            ->join(
                'reporting_periods as period',
                'period.id',
                '=',
                'monthly_rate_sheets.reporting_period_id',
            )
            ->with(['period', 'approver']);
        $recordsTotal = (clone $query)->count('monthly_rate_sheets.id');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $like = '%'.$search.'%';

                $builder
                    ->where('monthly_rate_sheets.sheet_no', 'like', $like)
                    ->orWhere('monthly_rate_sheets.status', 'like', $like)
                    ->orWhere('period.year', 'like', $like)
                    ->orWhere('period.month', 'like', $like);

                if (preg_match('/^(?<year>\d{4})-(?<month>\d{1,2})$/', $search, $periodMatch)) {
                    $builder->orWhere(function ($periodQuery) use ($periodMatch): void {
                        $periodQuery
                            ->where('period.year', (int) $periodMatch['year'])
                            ->where('period.month', (int) $periodMatch['month']);
                    });
                }
            });
        }

        $recordsFiltered = (clone $query)->count('monthly_rate_sheets.id');
        $sheets = $query
            ->orderBy($orderBy, $orderDirection)
            ->skip($start)
            ->take($length)
            ->get();
        $data = $sheets->map(fn (MonthlyRateSheet $sheet): array => [
            $sheet->id,
            '<span class="fw-semibold">'.e($sheet->sheet_no).'</span>',
            sprintf('%04d-%02d', $sheet->period->year, $sheet->period->month),
            '<span class="badge text-sm fw-semibold '.e($sheet->status->badgeClass()).' px-12 py-7 radius-4">'
                .e($sheet->status->label()).'</span>',
            $sheet->approved_at
                ? e($sheet->approved_at->format('d M Y, h:i A'))
                    .'<small class="d-block text-muted">'.e($sheet->approver?->name ?: 'System').'</small>'
                : '<span class="text-muted">&mdash;</span>',
            view(
                'backend.modules.monthly_rate_sheet.partials.history_action',
                compact('sheet'),
            )->render(),
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
