<?php

namespace App\Services\Purchase;

use App\Enums\Purchase\PurchaseOrderStatus;
use App\Exceptions\Purchase\InvalidPurchaseOrderException;
use App\Models\backend\Supplier;
use App\Models\backend\Warehouse;
use App\Models\Purchase\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PurchaseOrderService
{
    private const DOMAINS = [
        'raw_material' => [
            'item_table' => 'raw_materials', 'item_column' => 'raw_material_id',
            'unit_table' => 'raw_material_units',
        ],
        'part' => [
            'item_table' => 'parts', 'item_column' => 'part_id',
            'unit_table' => 'part_units',
        ],
    ];

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createDraft(array $header, array $lines, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($header, $lines, $userId) {
            $supplier = $this->supplier((int) $header['supplier_id']);
            $warehouse = $this->warehouse((int) $header['warehouse_id']);
            [$normalizedLines, $totals] = $this->normalize($lines, $header);

            $order = PurchaseOrder::query()->create([
                'order_no' => $this->nextNumber(),
                'branch_id' => $warehouse->branch_id,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'order_date' => $header['order_date'],
                'expected_date' => $header['expected_date'] ?? null,
                'status' => PurchaseOrderStatus::Draft,
                ...$totals,
                'note' => $header['note'] ?? null,
                'created_by' => $userId,
            ]);

            $this->insertLines($order, $normalizedLines);

            return $this->load($order);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function updateDraft(PurchaseOrder $order, array $header, array $lines): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $header, $lines) {
            $order = $this->lock($order);
            $this->assertStatus($order, PurchaseOrderStatus::Draft, 'Only a draft purchase order can be edited.');
            $supplier = $this->supplier((int) $header['supplier_id']);
            $warehouse = $this->warehouse((int) $header['warehouse_id']);
            [$normalizedLines, $totals] = $this->normalize($lines, $header);

            $order->update([
                'branch_id' => $warehouse->branch_id,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'order_date' => $header['order_date'],
                'expected_date' => $header['expected_date'] ?? null,
                ...$totals,
                'note' => $header['note'] ?? null,
            ]);

            $order->items()->delete();
            $this->insertLines($order, $normalizedLines);

            return $this->load($order);
        }, 3);
    }

    public function approve(PurchaseOrder $order, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $userId) {
            $order = $this->lock($order);
            if ($order->status === PurchaseOrderStatus::Approved) {
                return $this->load($order);
            }
            $this->assertStatus($order, PurchaseOrderStatus::Draft, 'Only a draft purchase order can be approved.');
            if (! $order->items()->exists()) {
                throw new InvalidPurchaseOrderException('At least one purchase order line is required.');
            }

            $order->update([
                'status' => PurchaseOrderStatus::Approved,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            return $this->load($order);
        }, 3);
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = $this->lock($order);
            if ($order->status === PurchaseOrderStatus::Cancelled) {
                return $this->load($order);
            }
            if (! in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Approved], true)) {
                throw new InvalidPurchaseOrderException('A partially or fully received purchase order cannot be cancelled.');
            }
            if (DB::table('purchase_receipt')->where('purchase_order_id', $order->id)->exists()) {
                throw new InvalidPurchaseOrderException('This purchase order already has a receipt and cannot be cancelled.');
            }
            $order->update(['status' => PurchaseOrderStatus::Cancelled]);

            return $this->load($order);
        }, 3);
    }

    public function deleteDraft(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = $this->lock($order);
            $this->assertStatus($order, PurchaseOrderStatus::Draft, 'Only a draft purchase order can be deleted.');
            $order->delete();
        }, 3);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $header
     * @return array{array<int, array<string, mixed>>, array<string, string>}
     */
    private function normalize(array $lines, array $header): array
    {
        if ($lines === []) {
            throw new InvalidPurchaseOrderException('At least one purchase order line is required.');
        }

        $normalized = [];
        $seen = [];
        $subtotal = '0.0000';

        foreach ($lines as $index => $line) {
            $domain = (string) ($line['domain'] ?? '');
            if (! isset(self::DOMAINS[$domain])) {
                throw new InvalidPurchaseOrderException('Line '.($index + 1).' has an invalid inventory type.');
            }
            $config = self::DOMAINS[$domain];
            $itemId = (int) ($line['item_id'] ?? 0);
            $unitId = (int) ($line['unit_id'] ?? 0);
            $key = $domain.':'.$itemId;
            if (isset($seen[$key])) {
                throw new InvalidPurchaseOrderException('The same inventory item cannot be entered more than once in one purchase order.');
            }
            $seen[$key] = true;

            $itemQuery = DB::table($config['item_table'])->where('id', $itemId)
                ->whereNull('deleted_at')->where('is_active', true);
            if ($domain === 'part') {
                $itemQuery->whereIn('procurement_mode', ['BUY', 'BOTH']);
            }
            $item = $itemQuery->first(['id', 'base_unit_id']);
            if (! $item) {
                throw new InvalidPurchaseOrderException('Line '.($index + 1).' contains an unavailable or non-purchasable item.');
            }

            $unit = DB::table('units')->where('id', $unitId)->whereNull('deleted_at')->where('is_active', true)->first(['id']);
            if (! $unit) {
                throw new InvalidPurchaseOrderException('Line '.($index + 1).' contains an unavailable unit.');
            }

            $factor = '1.000000';
            if ((int) $item->base_unit_id !== $unitId) {
                $factor = DB::table($config['unit_table'])
                    ->where($config['item_column'], $itemId)->where('unit_id', $unitId)
                    ->whereIn('usage_type', ['PURCHASE', 'ALL'])->value('conversion_to_base');
                if ($factor === null) {
                    throw new InvalidPurchaseOrderException('Line '.($index + 1).' uses a unit that is not approved for purchase.');
                }
            }

            $quantity = $this->decimal($line['quantity'] ?? '', 6, 14, false, 'quantity');
            $baseQuantity = $this->decimal($this->multiplyDecimal($quantity, (string) $factor, 6), 6, 14, false, 'base quantity');
            $unitRate = $this->decimal($line['unit_rate'] ?? '', 4, 16, false, 'unit rate');
            $lineTotal = $this->decimal($this->multiplyDecimal($quantity, $unitRate, 4), 4, 16, true, 'line total');
            $subtotal = $this->addDecimal($subtotal, $lineTotal, 4);

            $normalized[] = [
                'raw_material_id' => $domain === 'raw_material' ? $itemId : null,
                'part_id' => $domain === 'part' ? $itemId : null,
                'unit_id' => $unitId,
                'conversion_to_base' => $factor,
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'received_base_quantity' => '0.000000',
                'unit_rate' => $unitRate,
                'line_total' => $lineTotal,
            ];
        }

        $discountType = strtoupper(trim((string) ($header['discount_type'] ?? 'FLAT')));
        if (! in_array($discountType, ['FLAT', 'PERCENT'], true)) {
            throw new InvalidPurchaseOrderException('The discount type must be Flat or Percent.');
        }

        $discountValue = $this->decimal($header['discount_value'] ?? $header['discount'] ?? 0, 4, 16, true, 'discount value');
        if ($discountType === 'PERCENT') {
            if ($this->compareDecimal($discountValue, '100.0000', 4) > 0) {
                throw new InvalidPurchaseOrderException('Percentage discount cannot be greater than 100.');
            }
            $discountRatio = $this->integerToDecimal($this->digits($discountValue, 4), 6, 6);
            $discount = $this->multiplyDecimal($subtotal, $discountRatio, 4);
        } else {
            $discount = $discountValue;
        }

        $tax = $this->decimal($header['tax'] ?? 0, 4, 16, true, 'tax');
        if ($this->compareDecimal($discount, $subtotal, 4) > 0) {
            throw new InvalidPurchaseOrderException('Discount cannot be greater than the subtotal.');
        }
        $total = $this->addDecimal($this->subtractDecimal($subtotal, $discount, 4), $tax, 4);
        $total = $this->decimal($total, 4, 16, true, 'total');

        return [$normalized, [
            'subtotal' => $subtotal,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
        ]];
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function insertLines(PurchaseOrder $order, array $lines): void
    {
        $timestamp = now();
        foreach ($lines as $line) {
            DB::table('purchase_order_item')->insert([
                'purchase_order_id' => $order->id,
                ...$line,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    private function supplier(int $supplierId): Supplier
    {
        $supplier = Supplier::query()->whereKey($supplierId)->where('is_active', true)->first();
        if (! $supplier) {
            throw new InvalidPurchaseOrderException('The selected supplier is unavailable.');
        }

        return $supplier;
    }

    private function warehouse(int $warehouseId): Warehouse
    {
        $warehouse = Warehouse::query()->whereKey($warehouseId)->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))->first();
        if (! $warehouse) {
            throw new InvalidPurchaseOrderException('The selected warehouse is unavailable.');
        }

        return $warehouse;
    }

    private function lock(PurchaseOrder $order): PurchaseOrder
    {
        $locked = PurchaseOrder::query()->whereKey($order->getKey())->lockForUpdate()->first();
        if (! $locked) {
            throw new InvalidPurchaseOrderException('The purchase order does not exist.');
        }

        return $locked;
    }

    private function assertStatus(PurchaseOrder $order, PurchaseOrderStatus $status, string $message): void
    {
        if ($order->status !== $status) {
            throw new InvalidPurchaseOrderException($message);
        }
    }

    private function load(PurchaseOrder $order): PurchaseOrder
    {
        return $order->fresh([
            'branch', 'supplier', 'warehouse', 'createdBy', 'approvedBy',
            'items.rawMaterial.baseUnit', 'items.part.baseUnit', 'items.unit',
        ]);
    }

    private function nextNumber(): string
    {
        do {
            $number = 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (PurchaseOrder::query()->where('order_no', $number)->exists());

        return $number;
    }

    private function decimal(mixed $value, int $scale, int $maxIntegerDigits, bool $allowZero, string $field): string
    {
        $raw = is_float($value) ? rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.') : trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw new InvalidPurchaseOrderException("The {$field} must be a non-negative decimal number.");
        }
        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        if (strlen($integer) > $maxIntegerDigits || strlen($fraction) > $scale) {
            throw new InvalidPurchaseOrderException("The {$field} exceeds the supported precision.");
        }
        $normalized = $integer.'.'.str_pad($fraction, $scale, '0');
        if (! $allowZero && $normalized === '0.'.str_repeat('0', $scale)) {
            throw new InvalidPurchaseOrderException("The {$field} must be greater than zero.");
        }

        return $normalized;
    }

    private function multiplyDecimal(string $left, string $right, int $scale): string
    {
        [$li, $lf] = array_pad(explode('.', $left, 2), 2, '');
        [$ri, $rf] = array_pad(explode('.', $right, 2), 2, '');
        $product = $this->multiplyIntegers(ltrim($li.$lf, '0') ?: '0', ltrim($ri.$rf, '0') ?: '0');
        $sourceScale = strlen($lf) + strlen($rf);
        if ($sourceScale > $scale) {
            $cut = $sourceScale - $scale;
            $roundDigit = strlen($product) > $cut ? (int) $product[strlen($product) - $cut] : 0;
            $product = strlen($product) > $cut ? substr($product, 0, -$cut) : '0';
            if ($roundDigit >= 5) {
                $product = $this->addIntegers($product, '1');
            }
            $sourceScale = $scale;
        }

        return $this->integerToDecimal($product, $sourceScale, $scale);
    }

    private function addDecimal(string $left, string $right, int $scale): string
    {
        return $this->integerToDecimal($this->addIntegers($this->digits($left, $scale), $this->digits($right, $scale)), $scale, $scale);
    }

    private function subtractDecimal(string $left, string $right, int $scale): string
    {
        return $this->integerToDecimal($this->subtractIntegers($this->digits($left, $scale), $this->digits($right, $scale)), $scale, $scale);
    }

    private function compareDecimal(string $left, string $right, int $scale): int
    {
        $left = ltrim($this->digits($left, $scale), '0') ?: '0';
        $right = ltrim($this->digits($right, $scale), '0') ?: '0';

        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private function digits(string $decimal, int $scale): string
    {
        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');

        return (ltrim($integer, '0') ?: '0').substr(str_pad($fraction, $scale, '0'), 0, $scale);
    }

    private function integerToDecimal(string $digits, int $sourceScale, int $targetScale): string
    {
        $digits = ltrim($digits, '0') ?: '0';
        $digits = str_pad($digits, $sourceScale + 1, '0', STR_PAD_LEFT);
        $integer = $sourceScale === 0 ? $digits : substr($digits, 0, -$sourceScale);
        $fraction = $sourceScale === 0 ? '' : substr($digits, -$sourceScale);

        return (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, $targetScale, '0');
    }

    private function addIntegers(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        for ($i = strlen($left) - 1, $j = strlen($right) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
            $sum = ($i >= 0 ? (int) $left[$i] : 0) + ($j >= 0 ? (int) $right[$j] : 0) + $carry;
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private function subtractIntegers(string $left, string $right): string
    {
        $left = str_pad($left, max(strlen($left), strlen($right)), '0', STR_PAD_LEFT);
        $right = str_pad($right, strlen($left), '0', STR_PAD_LEFT);
        $borrow = 0;
        $result = '';
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            $digit = (int) $left[$i] - $borrow - (int) $right[$i];
            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = $digit.$result;
        }

        return ltrim($result, '0') ?: '0';
    }

    private function multiplyIntegers(string $left, string $right): string
    {
        $result = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $sum = $result[$position] + ((int) $left[$i] * (int) $right[$j]);
                $result[$position] = $sum % 10;
                $result[$position - 1] += intdiv($sum, 10);
            }
        }

        return ltrim(implode('', $result), '0') ?: '0';
    }
}
