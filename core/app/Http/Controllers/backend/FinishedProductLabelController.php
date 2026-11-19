<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\FinishedProduct;
use App\Services\Inventory\Ean13BarcodeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinishedProductLabelController extends Controller
{
    private const MAX_LABELS_PER_PRINT = 400;

    public function __construct(private readonly Ean13BarcodeService $barcodeService) {}

    public function index(): View
    {
        return view('backend.modules.barcode_label.index');
    }

    public function products(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));

        $query = FinishedProduct::query()
            ->where('is_active', true)
            ->with('baseUnit:id,name,code');

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('product_code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $results = $query
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'name', 'product_code', 'barcode', 'base_unit_id'])
            ->map(fn (FinishedProduct $product): array => [
                'id' => $product->id,
                'text' => "{$product->name} ({$product->product_code})",
                'barcode' => $product->barcode,
                'unit' => $product->baseUnit?->code ?? $product->baseUnit?->name,
            ]);

        return response()->json(['results' => $results]);
    }

    public function preview(Request $request): View
    {
        $data = $request->validate([
            'products' => ['required', 'array', 'min:1'],
            'products.*.finished_product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('finished_products', 'id')
                    ->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)),
            ],
            'products.*.quantity' => ['required', 'integer', 'min:1', 'max:400'],
        ], [
            'products.required' => 'Add at least one Finished Product.',
            'products.*.finished_product_id.distinct' => 'Each Finished Product may be selected only once.',
        ]);

        $requestedLabels = collect($data['products'])->sum('quantity');

        if ($requestedLabels > self::MAX_LABELS_PER_PRINT) {
            return back()
                ->withInput()
                ->withErrors(['products' => 'A single print job may contain up to 400 labels.']);
        }

        $productIds = collect($data['products'])->pluck('finished_product_id')->all();
        $products = FinishedProduct::query()
            ->whereKey($productIds)
            ->get()
            ->keyBy('id');

        $labels = collect($data['products'])->flatMap(function (array $line) use ($products): array {
            /** @var FinishedProduct $product */
            $product = $products->get((int) $line['finished_product_id']);
            $barcode = $this->barcodeService->assignTo($product);
            $label = [
                'name' => $product->name,
                'barcode' => $barcode,
                'svg' => $this->barcodeService->renderSvg($barcode),
            ];

            return array_fill(0, (int) $line['quantity'], $label);
        })->values();

        return view('backend.modules.barcode_label.print', [
            'sheets' => $labels->chunk(40),
            'labelCount' => $labels->count(),
        ]);
    }
}
