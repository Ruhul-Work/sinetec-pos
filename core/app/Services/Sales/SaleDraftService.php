<?php

namespace App\Services\Sales;

use App\Enums\Sales\SaleChannel;
use App\Enums\Sales\SaleStatus;
use App\Exceptions\Sales\InvalidSaleException;
use App\Models\backend\Customer;
use App\Models\backend\FinishedProduct;
use App\Models\backend\Warehouse;
use App\Models\Sales\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ValueError;

final class SaleDraftService
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createDraft(array $header, array $items, ?int $userId): Sale
    {
        return DB::transaction(function () use ($header, $items, $userId): Sale {
            $channel = $this->channel($header['channel'] ?? null);
            $warehouse = $this->warehouse((int) ($header['warehouse_id'] ?? 0));
            $customer = $this->customer($header['customer_id'] ?? null, $channel);
            [$normalizedItems, $totals] = $this->normalizeItems($items, $header, $channel);

            $sale = Sale::query()->create([
                'sale_no' => $this->nextNumber(),
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'customer_id' => $customer?->id,
                'customer_reference' => $this->nullableText($header['customer_reference'] ?? null),
                'channel' => $channel,
                'sale_at' => $header['sale_at'] ?? now(),
                'due_date' => $this->dueDate(
                    $header['due_date'] ?? null,
                    $channel,
                    $header['sale_at'] ?? now(),
                ),
                'status' => SaleStatus::Draft,
                ...$totals,
                'paid_amount' => '0.0000',
                'due_amount' => $totals['total'],
                'note' => $this->nullableText($header['note'] ?? null),
                'created_by' => $userId,
            ]);

            $sale->items()->createMany($normalizedItems);

            return $this->load($sale);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $items
     */
    public function updateDraft(Sale $sale, array $header, array $items): Sale
    {
        return DB::transaction(function () use ($sale, $header, $items): Sale {
            $sale = $this->lock($sale);
            $this->assertDraft($sale);

            $channel = $this->channel($header['channel'] ?? null);
            $warehouse = $this->warehouse((int) ($header['warehouse_id'] ?? 0));
            $customer = $this->customer($header['customer_id'] ?? null, $channel);
            [$normalizedItems, $totals] = $this->normalizeItems($items, $header, $channel);

            $sale->update([
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'customer_id' => $customer?->id,
                'customer_reference' => $this->nullableText($header['customer_reference'] ?? null),
                'channel' => $channel,
                'sale_at' => $header['sale_at'] ?? $sale->sale_at,
                'due_date' => $this->dueDate(
                    $header['due_date'] ?? null,
                    $channel,
                    $header['sale_at'] ?? $sale->sale_at,
                ),
                ...$totals,
                'paid_amount' => '0.0000',
                'due_amount' => $totals['total'],
                'note' => $this->nullableText($header['note'] ?? null),
            ]);

            $sale->items()->delete();
            $sale->items()->createMany($normalizedItems);

            return $this->load($sale);
        }, 3);
    }

    public function deleteDraft(Sale $sale): void
    {
        DB::transaction(function () use ($sale): void {
            $sale = $this->lock($sale);
            $this->assertDraft($sale);
            $sale->delete();
        }, 3);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $header
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>}
     */
    private function normalizeItems(array $items, array $header, SaleChannel $channel): array
    {
        if ($items === []) {
            throw new InvalidSaleException('At least one Finished Product is required.');
        }

        $normalizedItems = [];
        $seenProducts = [];
        $subtotal = '0.0000';

        foreach ($items as $index => $item) {
            $lineNumber = $index + 1;
            $productId = (int) ($item['finished_product_id'] ?? 0);
            $unitId = (int) ($item['unit_id'] ?? 0);

            if (isset($seenProducts[$productId])) {
                throw new InvalidSaleException('The same Finished Product cannot be added more than once.');
            }

            $product = FinishedProduct::query()
                ->whereKey($productId)
                ->where('is_active', true)
                ->first();

            if (! $product) {
                throw new InvalidSaleException("Line {$lineNumber} contains an unavailable Finished Product.");
            }

            $seenProducts[$productId] = true;
            $conversion = $this->saleUnitConversion($product, $unitId, $lineNumber);
            $quantity = $this->decimal($item['quantity'] ?? null, 6, 14, false, "line {$lineNumber} quantity");
            $baseQuantity = $this->multiply($quantity, $conversion, 6);
            $unitPrice = $this->unitPrice($item, $product, $channel, $conversion, $lineNumber);
            $gross = $this->multiply($quantity, $unitPrice, 4);
            $lineDiscount = $this->decimal($item['discount'] ?? 0, 4, 16, true, "line {$lineNumber} discount");
            $lineTax = $this->decimal($item['tax'] ?? 0, 4, 16, true, "line {$lineNumber} tax");

            if (bccomp($lineDiscount, $gross, 4) === 1) {
                throw new InvalidSaleException("Line {$lineNumber} discount cannot exceed its gross amount.");
            }

            $lineTotal = bcadd(bcsub($gross, $lineDiscount, 4), $lineTax, 4);
            $subtotal = bcadd($subtotal, $lineTotal, 4);

            $normalizedItems[] = [
                'finished_product_id' => $product->id,
                'unit_id' => $unitId,
                'conversion_to_base' => $conversion,
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'unit_price' => $unitPrice,
                'discount' => $lineDiscount,
                'tax' => $lineTax,
                'line_total' => $lineTotal,
                'unit_cost' => null,
            ];
        }

        return [$normalizedItems, $this->documentTotals($subtotal, $header)];
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array<string, string>
     */
    private function documentTotals(string $subtotal, array $header): array
    {
        $discountType = strtoupper(trim((string) ($header['discount_type'] ?? 'FLAT')));
        if (! in_array($discountType, ['FLAT', 'PERCENT'], true)) {
            throw new InvalidSaleException('Discount type must be Flat or Percent.');
        }

        $discountValue = $this->decimal(
            $header['discount_value'] ?? 0,
            4,
            16,
            true,
            'discount value',
        );

        if ($discountType === 'PERCENT') {
            if (bccomp($discountValue, '100.0000', 4) === 1) {
                throw new InvalidSaleException('Percentage discount cannot exceed 100.');
            }

            $discountRatio = bcdiv($discountValue, '100', 8);
            $discount = $this->multiply($subtotal, $discountRatio, 4);
        } else {
            $discount = $discountValue;
        }

        if (bccomp($discount, $subtotal, 4) === 1) {
            throw new InvalidSaleException('Discount cannot exceed the subtotal.');
        }

        $tax = $this->decimal($header['tax'] ?? 0, 4, 16, true, 'tax');
        $total = bcadd(bcsub($subtotal, $discount, 4), $tax, 4);

        return [
            'subtotal' => $subtotal,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
        ];
    }

    private function saleUnitConversion(FinishedProduct $product, int $unitId, int $lineNumber): string
    {
        if ($unitId <= 0) {
            throw new InvalidSaleException("Line {$lineNumber} requires a selling unit.");
        }

        if ((int) $product->base_unit_id === $unitId) {
            return '1.000000';
        }

        $conversion = DB::table('finished_product_units')
            ->where('finished_product_id', $product->id)
            ->where('unit_id', $unitId)
            ->whereIn('usage_type', ['SALE', 'ALL'])
            ->value('conversion_to_base');

        if ($conversion === null) {
            throw new InvalidSaleException("Line {$lineNumber} uses a unit that is not approved for sale.");
        }

        return $this->decimal($conversion, 6, 14, false, "line {$lineNumber} conversion");
    }

    /** @param array<string, mixed> $item */
    private function unitPrice(
        array $item,
        FinishedProduct $product,
        SaleChannel $channel,
        string $conversion,
        int $lineNumber,
    ): string {
        if (filled($item['unit_price'] ?? null)) {
            return $this->decimal($item['unit_price'], 4, 16, false, "line {$lineNumber} unit price");
        }

        $basePrice = $channel === SaleChannel::Wholesale
            ? $product->wholesale_price
            : $product->retail_price;

        if ($basePrice === null) {
            throw new InvalidSaleException("Line {$lineNumber} requires a selling price.");
        }

        return $this->multiply((string) $basePrice, $conversion, 4);
    }

    private function channel(mixed $value): SaleChannel
    {
        try {
            return SaleChannel::from(strtoupper(trim((string) $value)));
        } catch (ValueError) {
            throw new InvalidSaleException('Sale channel must be POS or Wholesale.');
        }
    }

    private function warehouse(int $warehouseId): Warehouse
    {
        $warehouse = Warehouse::query()
            ->whereKey($warehouseId)
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))
            ->first();

        if (! $warehouse) {
            throw new InvalidSaleException('Select an active selling warehouse.');
        }

        $currentBranchId = current_branch_id();
        if ($currentBranchId !== null && (int) $warehouse->branch_id !== $currentBranchId) {
            throw new InvalidSaleException('The selected warehouse is outside the active branch.');
        }

        return $warehouse;
    }

    private function customer(mixed $customerId, SaleChannel $channel): ?Customer
    {
        if (! filled($customerId)) {
            if ($channel === SaleChannel::Wholesale) {
                throw new InvalidSaleException('Wholesale Sale requires a customer.');
            }

            return null;
        }

        $customer = Customer::query()
            ->whereKey((int) $customerId)
            ->where('is_active', true)
            ->first();

        if (! $customer) {
            throw new InvalidSaleException('The selected customer is unavailable.');
        }

        return $customer;
    }

    private function assertDraft(Sale $sale): void
    {
        if ($sale->status !== SaleStatus::Draft) {
            throw new InvalidSaleException('Only a Draft Sale can be changed or deleted.');
        }
    }

    private function lock(Sale $sale): Sale
    {
        return Sale::query()
            ->lockForUpdate()
            ->find($sale->id)
            ?? throw new InvalidSaleException('The Sale document does not exist.');
    }

    private function load(Sale $sale): Sale
    {
        return $sale->fresh([
            'branch',
            'warehouse',
            'customer',
            'items.finishedProduct.baseUnit',
            'items.unit',
            'createdBy',
        ]);
    }

    private function nextNumber(): string
    {
        do {
            $number = 'SAL-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (Sale::query()->where('sale_no', $number)->exists());

        return $number;
    }

    private function decimal(
        mixed $value,
        int $scale,
        int $maxIntegerDigits,
        bool $allowZero,
        string $field,
    ): string {
        $raw = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw new InvalidSaleException("The {$field} must be a non-negative decimal number.");
        }

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        if (strlen($integer) > $maxIntegerDigits || strlen($fraction) > $scale) {
            throw new InvalidSaleException("The {$field} exceeds the supported precision.");
        }

        $normalized = $integer.'.'.str_pad($fraction, $scale, '0');
        if (! $allowZero && bccomp($normalized, '0', $scale) === 0) {
            throw new InvalidSaleException("The {$field} must be greater than zero.");
        }

        return $normalized;
    }

    private function multiply(string $left, string $right, int $scale): string
    {
        return bcadd(bcmul($left, $right, $scale + 2), '0.'.str_repeat('0', $scale).'5', $scale);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function dueDate(mixed $value, SaleChannel $channel, mixed $saleAt): ?string
    {
        $date = $this->nullableText($value);

        if ($channel === SaleChannel::Wholesale && $date === null) {
            throw new InvalidSaleException('Wholesale Sale requires a due date.');
        }

        if ($date === null) {
            return null;
        }

        try {
            $dueDate = Carbon::parse($date)->startOfDay();
            $saleDate = Carbon::parse($saleAt)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidSaleException('The Wholesale due date is invalid.');
        }

        if ($dueDate->lt($saleDate)) {
            throw new InvalidSaleException('Wholesale due date cannot be before the invoice date.');
        }

        return $dueDate->format('Y-m-d');
    }
}
