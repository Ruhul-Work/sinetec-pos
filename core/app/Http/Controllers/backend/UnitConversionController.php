<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\Unit;
use App\Models\backend\UnitConversion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UnitConversionController extends Controller
{
    public function index(): View
    {
        return view('backend.modules.unit_conversion.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['id', 'from_unit_id', 'to_unit_id', 'factor', 'updated_at'];
        $draw = (int) $request->input('draw');
        $start = max(0, (int) $request->input('start', 0));
        $length = min(100, max(1, (int) $request->input('length', 10)));
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search.value', ''));

        $query = UnitConversion::query()->with(['fromUnit:id,name,code', 'toUnit:id,name,code']);
        $total = (clone $query)->count();

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->whereHas('fromUnit', function ($unitQuery) use ($search): void {
                    $unitQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                })->orWhereHas('toUnit', function ($unitQuery) use ($search): void {
                    $unitQuery->where('name', 'like', "%{$search}%")
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

        $data = $rows->map(function (UnitConversion $conversion): array {
            $factor = $this->formatDecimal($conversion->factor);
            $factorValue = (float) $conversion->factor;
            $inverse = $factorValue > 0 ? $this->formatDecimal(1 / $factorValue, 10) : 'Invalid';
            $fromCode = e($conversion->fromUnit?->code ?? '—');
            $toCode = e($conversion->toUnit?->code ?? '—');

            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">
                <a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main AjaxModal"
                    data-ajax-modal="'.route('units.conversions.editModal', $conversion).'"
                    data-size="md" data-onsuccess="UnitConversionsIndex.onSaved"
                    title="Edit" aria-label="Edit unit conversion">
                    <iconify-icon icon="lucide:edit"></iconify-icon>
                </a>
                <a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-conversion-delete"
                    data-url="'.route('units.conversions.destroy', $conversion).'"
                    title="Delete" aria-label="Delete unit conversion">
                    <iconify-icon icon="mdi:delete"></iconify-icon>
                </a>
            </div>';

            return [
                $conversion->id,
                '<strong>'.e($conversion->fromUnit?->name ?? 'Deleted unit').'</strong><br><code>'.$fromCode.'</code>',
                '<strong>'.e($conversion->toUnit?->name ?? 'Deleted unit').'</strong><br><code>'.$toCode.'</code>',
                '<span class="fw-semibold">1 '.$fromCode.' = '.$factor.' '.$toCode.'</span>',
                '<span class="text-muted">1 '.$toCode.' = '.$inverse.' '.$fromCode.'</span>',
                e($conversion->updated_at?->format('d M Y, h:i A') ?? '—'),
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
        return $this->modalView(new UnitConversion(), route('units.conversions.store'), 'POST', 'Add Unit Conversion');
    }

    public function store(Request $request): JsonResponse
    {
        $conversion = UnitConversion::create($this->validatedData($request));

        return response()->json([
            'success' => true,
            'msg' => 'Unit conversion created successfully.',
            'id' => $conversion->id,
        ], 201);
    }

    public function editModal(UnitConversion $unitConversion): View
    {
        return $this->modalView(
            $unitConversion,
            route('units.conversions.update', $unitConversion),
            'PUT',
            'Edit Unit Conversion'
        );
    }

    public function update(Request $request, UnitConversion $unitConversion): JsonResponse
    {
        $unitConversion->update($this->validatedData($request, $unitConversion));

        return response()->json(['success' => true, 'msg' => 'Unit conversion updated successfully.']);
    }

    public function destroy(UnitConversion $unitConversion): JsonResponse
    {
        $unitConversion->delete();

        return response()->json(['success' => true, 'msg' => 'Unit conversion deleted successfully.']);
    }

    private function modalView(UnitConversion $conversion, string $action, string $method, string $title): View
    {
        $units = Unit::query()->orderBy('name')->get(['id', 'name', 'code', 'is_active']);

        return view('backend.modules.unit_conversion.modal', compact('conversion', 'units', 'action', 'method', 'title'));
    }

    private function validatedData(Request $request, ?UnitConversion $conversion = null): array
    {
        $data = $request->validate([
            'from_unit_id' => [
                'required',
                'integer',
                Rule::exists('units', 'id')->whereNull('deleted_at'),
                'different:to_unit_id',
            ],
            'to_unit_id' => [
                'required',
                'integer',
                Rule::exists('units', 'id')->whereNull('deleted_at'),
                'different:from_unit_id',
            ],
            'factor' => ['required', 'numeric', 'gte:0.000001', 'max:99999999999999.999999'],
        ]);

        $duplicate = UnitConversion::query()
            ->when($conversion?->exists, fn ($query) => $query->where('id', '!=', $conversion->getKey()))
            ->where(function ($query) use ($data): void {
                $query->where(function ($pair) use ($data): void {
                    $pair->where('from_unit_id', $data['from_unit_id'])
                        ->where('to_unit_id', $data['to_unit_id']);
                })->orWhere(function ($pair) use ($data): void {
                    $pair->where('from_unit_id', $data['to_unit_id'])
                        ->where('to_unit_id', $data['from_unit_id']);
                });
            })
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'from_unit_id' => 'This unit pair already has a conversion. Reverse conversion is calculated automatically.',
            ]);
        }

        return $data;
    }

    private function formatDecimal(string|float|int $value, int $scale = 6): string
    {
        return rtrim(rtrim(number_format((float) $value, $scale, '.', ''), '0'), '.');
    }
}
