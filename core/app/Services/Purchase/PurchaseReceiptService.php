<?php

namespace App\Services\Purchase;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Purchase\PurchaseOrderStatus;
use App\Enums\Purchase\PurchaseReceiptStatus;
use App\Exceptions\Purchase\InvalidPurchaseReceiptException;
use App\Models\backend\Supplier;
use App\Models\backend\Warehouse;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseOrderItem;
use App\Models\Purchase\PurchaseReceipt;
use App\Services\Finance\PurchaseJournalService;
use App\Services\Inventory\PartStockPostingService;
use App\Services\Inventory\RawMaterialStockPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PurchaseReceiptService
{
    private const DOMAINS = [
        'raw_material' => ['item_table' => 'raw_materials', 'item_column' => 'raw_material_id', 'unit_table' => 'raw_material_units'],
        'part' => ['item_table' => 'parts', 'item_column' => 'part_id', 'unit_table' => 'part_units'],
    ];

    public function __construct(
        private readonly RawMaterialStockPostingService $rawMaterialStock,
        private readonly PartStockPostingService $partStock,
        private readonly PurchaseJournalService $purchaseJournal,
    ) {}

    /** @param array<string, mixed> $header @param array<int, array<string, mixed>> $lines */
    public function createDraft(array $header, array $lines, ?int $userId = null): PurchaseReceipt
    {
        return DB::transaction(function () use ($header, $lines, $userId) {
            [$supplier, $warehouse, $order] = $this->foundation($header);
            [$normalizedLines, $totals] = $this->normalize($lines, $header, $order);

            $receipt = PurchaseReceipt::query()->create([
                'receipt_no' => $this->nextNumber(),
                'purchase_order_id' => $order?->id,
                'branch_id' => $warehouse->branch_id,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'receipt_date' => $header['receipt_date'],
                'supplier_invoice_no' => $header['supplier_invoice_no'] ?? null,
                'supplier_invoice_path' => $header['supplier_invoice_path'] ?? null,
                'status' => PurchaseReceiptStatus::Draft,
                ...$totals,
                'note' => $header['note'] ?? null,
                'created_by' => $userId,
            ]);

            $this->insertLines($receipt, $normalizedLines);

            return $this->load($receipt);
        }, 3);
    }

    /** @param array<string, mixed> $header @param array<int, array<string, mixed>> $lines */
    public function updateDraft(PurchaseReceipt $receipt, array $header, array $lines): PurchaseReceipt
    {
        return DB::transaction(function () use ($receipt, $header, $lines) {
            $receipt = $this->lock($receipt);
            $this->assertDraft($receipt);
            [$supplier, $warehouse, $order] = $this->foundation($header);
            if ((int) ($receipt->purchase_order_id ?? 0) !== (int) ($order?->id ?? 0)) {
                throw new InvalidPurchaseReceiptException('The purchase-order source of an existing receipt cannot be changed.');
            }
            [$normalizedLines, $totals] = $this->normalize($lines, $header, $order);

            $receipt->update([
                'purchase_order_id' => $order?->id,
                'branch_id' => $warehouse->branch_id,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'receipt_date' => $header['receipt_date'],
                'supplier_invoice_no' => $header['supplier_invoice_no'] ?? null,
                'supplier_invoice_path' => $header['supplier_invoice_path'] ?? $receipt->supplier_invoice_path,
                ...$totals,
                'note' => $header['note'] ?? null,
            ]);
            $receipt->items()->delete();
            $this->insertLines($receipt, $normalizedLines);

            return $this->load($receipt);
        }, 3);
    }

    public function post(PurchaseReceipt $receipt, ?int $userId = null): PurchaseReceipt
    {
        return DB::transaction(function () use ($receipt, $userId) {
            $receipt = $this->lock($receipt);
            if ($receipt->status === PurchaseReceiptStatus::Posted) {
                $this->purchaseJournal->createReceiptDraft($receipt, $userId);

                return $this->load($receipt);
            }
            $this->assertDraft($receipt);
            $items = $receipt->items()->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw new InvalidPurchaseReceiptException('At least one receipt line is required before posting.');
            }
            $postedAt = now();
            $movementAt = $receipt->receipt_date->setTime(
                $postedAt->hour,
                $postedAt->minute,
                $postedAt->second,
            );

            $order = null;
            if ($receipt->purchase_order_id) {
                $order = PurchaseOrder::query()->whereKey($receipt->purchase_order_id)->lockForUpdate()->first();
                if (! $order || ! in_array($order->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Partial], true)) {
                    throw new InvalidPurchaseReceiptException('The linked purchase order is not available for receiving.');
                }
            }

            foreach ($items as $item) {
                $domain = $item->raw_material_id ? 'raw_material' : 'part';
                $itemId = (int) ($item->raw_material_id ?: $item->part_id);

                if ($order) {
                    $orderItem = PurchaseOrderItem::query()->whereKey($item->purchase_order_item_id)->lockForUpdate()->first();
                    if (! $orderItem || (int) $orderItem->purchase_order_id !== (int) $order->id) {
                        throw new InvalidPurchaseReceiptException('A receipt line is not linked to the selected purchase order.');
                    }
                    $expectedId = (int) ($orderItem->raw_material_id ?: $orderItem->part_id);
                    if ($expectedId !== $itemId || ($orderItem->raw_material_id !== null) !== ($item->raw_material_id !== null)) {
                        throw new InvalidPurchaseReceiptException('A receipt item does not match its purchase order line.');
                    }
                    $newReceived = $this->addDecimal($orderItem->received_base_quantity, $item->base_quantity, 6);
                    if ($this->compareDecimal($newReceived, $orderItem->base_quantity, 6) > 0) {
                        throw new InvalidPurchaseReceiptException('Received quantity cannot exceed the pending purchase order quantity.');
                    }
                    $orderItem->update(['received_base_quantity' => $newReceived]);
                }

                $posting = new StockPostingData(
                    itemId: $itemId,
                    branchId: (int) $receipt->branch_id,
                    warehouseId: (int) $receipt->warehouse_id,
                    movementType: StockMovementType::PurchaseReceipt,
                    direction: StockDirection::In,
                    quantity: $item->base_quantity,
                    sourceType: 'purchase_receipt',
                    sourceId: (int) $receipt->id,
                    postingKey: 'purchase_receipt:'.$receipt->id.':item:'.$item->id,
                    movementAt: $movementAt,
                    unitCost: $this->divideDecimal($item->unit_rate, $item->conversion_to_base, 4),
                    sourceItemId: (int) $item->id,
                    note: $receipt->supplier_invoice_no
                        ? 'Supplier invoice: '.$receipt->supplier_invoice_no
                        : ($receipt->supplier_invoice_path ? 'Supplier invoice attached' : null),
                    postedBy: $userId,
                );

                ($domain === 'raw_material' ? $this->rawMaterialStock : $this->partStock)->post($posting);
            }

            if ($order) {
                $hasPending = PurchaseOrderItem::query()->where('purchase_order_id', $order->id)
                    ->whereColumn('received_base_quantity', '<', 'base_quantity')->exists();
                $order->update(['status' => $hasPending ? PurchaseOrderStatus::Partial : PurchaseOrderStatus::Received]);
            }

            $this->purchaseJournal->createReceiptDraft($receipt, $userId);

            $receipt->update([
                'status' => PurchaseReceiptStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => $postedAt,
            ]);

            return $this->load($receipt);
        }, 3);
    }

    public function deleteDraft(PurchaseReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt) {
            $receipt = $this->lock($receipt);
            $this->assertDraft($receipt);
            $receipt->delete();
        }, 3);
    }

    /** @param array<string, mixed> $header @return array{Supplier, Warehouse, PurchaseOrder|null} */
    private function foundation(array $header): array
    {
        $supplier = Supplier::query()->whereKey((int) $header['supplier_id'])->where('is_active', true)->first();
        $warehouse = Warehouse::query()->whereKey((int) $header['warehouse_id'])->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))->first();
        if (! $supplier || ! $warehouse) {
            throw new InvalidPurchaseReceiptException('The selected supplier or warehouse is unavailable.');
        }

        $order = null;
        if (! empty($header['purchase_order_id'])) {
            $order = PurchaseOrder::query()->whereKey((int) $header['purchase_order_id'])->lockForUpdate()->first();
            if (! $order || ! in_array($order->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Partial], true)) {
                throw new InvalidPurchaseReceiptException('Only an approved or partially received purchase order can be selected.');
            }
            if ((int) $order->supplier_id !== (int) $supplier->id || (int) $order->warehouse_id !== (int) $warehouse->id) {
                throw new InvalidPurchaseReceiptException('Supplier and warehouse must match the selected purchase order.');
            }
        }

        return [$supplier, $warehouse, $order];
    }

    /** @param array<int, array<string, mixed>> $lines @param array<string, mixed> $header */
    private function normalize(array $lines, array $header, ?PurchaseOrder $order): array
    {
        if ($lines === []) {
            throw new InvalidPurchaseReceiptException('At least one receipt line is required.');
        }
        $normalized = [];
        $seen = [];
        $subtotal = '0.0000';

        foreach ($lines as $index => $line) {
            $domain = (string) ($line['domain'] ?? '');
            if (! isset(self::DOMAINS[$domain])) {
                throw new InvalidPurchaseReceiptException('Line '.($index + 1).' has an invalid inventory type.');
            }
            $config = self::DOMAINS[$domain];
            $itemId = (int) ($line['item_id'] ?? 0);
            $unitId = (int) ($line['unit_id'] ?? 0);
            $orderItemId = ! empty($line['purchase_order_item_id']) ? (int) $line['purchase_order_item_id'] : null;
            $key = $order ? 'po:'.$orderItemId : $domain.':'.$itemId;
            if (isset($seen[$key])) {
                throw new InvalidPurchaseReceiptException('The same inventory item cannot be entered more than once.');
            }
            $seen[$key] = true;

            $itemQuery = DB::table($config['item_table'])->where('id', $itemId)->whereNull('deleted_at')->where('is_active', true);
            if ($domain === 'part') {
                $itemQuery->whereIn('procurement_mode', ['BUY', 'BOTH']);
            }
            $inventoryItem = $itemQuery->first(['id', 'base_unit_id']);
            if (! $inventoryItem) {
                throw new InvalidPurchaseReceiptException('Line '.($index + 1).' contains an unavailable item.');
            }

            $factor = '1.000000';
            if ((int) $inventoryItem->base_unit_id !== $unitId) {
                $factor = DB::table($config['unit_table'])->where($config['item_column'], $itemId)->where('unit_id', $unitId)
                    ->whereIn('usage_type', ['PURCHASE', 'ALL'])->value('conversion_to_base');
                if ($factor === null) {
                    throw new InvalidPurchaseReceiptException('Line '.($index + 1).' uses a unit not approved for purchase.');
                }
            }

            if ($order) {
                $orderItem = PurchaseOrderItem::query()->whereKey($orderItemId)->where('purchase_order_id', $order->id)->first();
                if (! $orderItem) {
                    throw new InvalidPurchaseReceiptException('Line '.($index + 1).' is not part of the selected purchase order.');
                }
                if ((int) ($orderItem->raw_material_id ?: $orderItem->part_id) !== $itemId || ($orderItem->raw_material_id !== null) !== ($domain === 'raw_material')) {
                    throw new InvalidPurchaseReceiptException('Line '.($index + 1).' does not match its purchase order item.');
                }
            } elseif ($orderItemId !== null) {
                throw new InvalidPurchaseReceiptException('A direct receipt cannot contain a purchase order line reference.');
            }

            $quantity = $this->decimal($line['quantity'] ?? '', 6, 14, false, 'quantity');
            $baseQuantity = $this->decimal($this->multiplyDecimal($quantity, (string) $factor, 6), 6, 14, false, 'base quantity');
            if ($order && $this->compareDecimal($baseQuantity, $this->subtractDecimal($orderItem->base_quantity, $orderItem->received_base_quantity, 6), 6) > 0) {
                throw new InvalidPurchaseReceiptException('Line '.($index + 1).' exceeds the currently pending order quantity.');
            }
            $unitRate = $this->decimal($line['unit_rate'] ?? '', 4, 16, false, 'unit rate');
            $lineTotal = $this->multiplyDecimal($quantity, $unitRate, 4);
            $subtotal = $this->addDecimal($subtotal, $lineTotal, 4);
            $normalized[] = [
                'purchase_order_item_id' => $orderItemId,
                'raw_material_id' => $domain === 'raw_material' ? $itemId : null,
                'part_id' => $domain === 'part' ? $itemId : null,
                'unit_id' => $unitId, 'conversion_to_base' => $factor,
                'quantity' => $quantity, 'base_quantity' => $baseQuantity,
                'unit_rate' => $unitRate, 'line_total' => $lineTotal,
            ];
        }

        $discountType = strtoupper(trim((string) ($header['discount_type'] ?? 'FLAT')));
        if (! in_array($discountType, ['FLAT', 'PERCENT'], true)) {
            throw new InvalidPurchaseReceiptException('The discount type must be Flat or Percent.');
        }
        $discountValue = $this->decimal($header['discount_value'] ?? 0, 4, 16, true, 'discount value');
        if ($discountType === 'PERCENT' && $this->compareDecimal($discountValue, '100.0000', 4) > 0) {
            throw new InvalidPurchaseReceiptException('Percentage discount cannot be greater than 100.');
        }
        $discount = $discountType === 'PERCENT'
            ? $this->multiplyDecimal($subtotal, $this->integerToDecimal($this->digits($discountValue, 4), 6, 6), 4)
            : $discountValue;
        if ($this->compareDecimal($discount, $subtotal, 4) > 0) {
            throw new InvalidPurchaseReceiptException('Discount cannot be greater than the subtotal.');
        }
        $tax = $this->decimal($header['tax'] ?? 0, 4, 16, true, 'tax');
        $total = $this->addDecimal($this->subtractDecimal($subtotal, $discount, 4), $tax, 4);

        return [$normalized, [
            'subtotal' => $subtotal,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
        ]];
    }

    private function insertLines(PurchaseReceipt $receipt, array $lines): void
    {
        $now = now();
        foreach ($lines as $line) {
            DB::table('purchase_receipt_item')->insert(['purchase_receipt_id' => $receipt->id, ...$line, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private function lock(PurchaseReceipt $receipt): PurchaseReceipt
    {
        return PurchaseReceipt::query()->whereKey($receipt->id)->lockForUpdate()->first()
            ?? throw new InvalidPurchaseReceiptException('The purchase receipt does not exist.');
    }

    private function assertDraft(PurchaseReceipt $receipt): void
    {
        if ($receipt->status !== PurchaseReceiptStatus::Draft) {
            throw new InvalidPurchaseReceiptException('Only a draft purchase receipt can be changed or posted.');
        }
    }

    private function load(PurchaseReceipt $receipt): PurchaseReceipt
    {
        return $receipt->fresh(['order', 'branch', 'supplier', 'warehouse', 'createdBy', 'postedBy', 'items.rawMaterial.baseUnit', 'items.part.baseUnit', 'items.unit']);
    }

    private function nextNumber(): string
    {
        do {
            $number = 'PR-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (PurchaseReceipt::query()->where('receipt_no', $number)->exists());

        return $number;
    }

    private function decimal(mixed $value, int $scale, int $maxIntegerDigits, bool $allowZero, string $field): string
    {
        $raw = is_float($value) ? rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.') : trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw new InvalidPurchaseReceiptException("The {$field} must be a non-negative decimal number.");
        }
        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        if (strlen($integer) > $maxIntegerDigits || strlen($fraction) > $scale) {
            throw new InvalidPurchaseReceiptException("The {$field} exceeds the supported precision.");
        }
        $normalized = $integer.'.'.str_pad($fraction, $scale, '0');
        if (! $allowZero && $normalized === '0.'.str_repeat('0', $scale)) {
            throw new InvalidPurchaseReceiptException("The {$field} must be greater than zero.");
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

    private function divideDecimal(string $left, string $right, int $scale): string
    {
        $left = $this->decimal($left, 4, 16, true, 'unit rate');
        $right = $this->decimal($right, 6, 14, false, 'conversion factor');
        $numerator = (ltrim($this->digits($left, 4), '0') ?: '0').str_repeat('0', 6 - 4 + $scale);
        $denominator = ltrim($this->digits($right, 6), '0') ?: '0';
        [$quotient, $remainder] = $this->divideIntegers($numerator, $denominator);
        if ($this->compareIntegers($this->multiplyIntegers($remainder, '2'), $denominator) >= 0) {
            $quotient = $this->addIntegers($quotient, '1');
        }

        return $this->integerToDecimal($quotient, $scale, $scale);
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
        return $this->compareIntegers($this->digits($left, $scale), $this->digits($right, $scale));
    }

    private function digits(string $decimal, int $scale): string
    {
        [$i, $f] = array_pad(explode('.', $decimal, 2), 2, '');

        return (ltrim($i, '0') ?: '0').substr(str_pad($f, $scale, '0'), 0, $scale);
    }

    private function integerToDecimal(string $digits, int $sourceScale, int $targetScale): string
    {
        $digits = ltrim($digits, '0') ?: '0';
        $digits = str_pad($digits, $sourceScale + 1, '0', STR_PAD_LEFT);
        $i = $sourceScale === 0 ? $digits : substr($digits, 0, -$sourceScale);
        $f = $sourceScale === 0 ? '' : substr($digits, -$sourceScale);

        return (ltrim($i, '0') ?: '0').'.'.str_pad($f, $targetScale, '0');
    }

    private function compareIntegers(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
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
                $p = $i + $j + 1;
                $sum = $result[$p] + ((int) $left[$i] * (int) $right[$j]);
                $result[$p] = $sum % 10;
                $result[$p - 1] += intdiv($sum, 10);
            }
        }

        return ltrim(implode('', $result), '0') ?: '0';
    }

    /** @return array{string,string} */
    private function divideIntegers(string $numerator, string $denominator): array
    {
        $quotient = '';
        $remainder = '0';
        foreach (str_split(ltrim($numerator, '0') ?: '0') as $digit) {
            $remainder = ltrim($remainder.$digit, '0') ?: '0';
            $q = 0;
            for ($candidate = 9; $candidate >= 1; $candidate--) {
                if ($this->compareIntegers($this->multiplyIntegers($denominator, (string) $candidate), $remainder) <= 0) {
                    $q = $candidate;
                    break;
                }
            }
            $quotient .= $q;
            if ($q) {
                $remainder = $this->subtractIntegers($remainder, $this->multiplyIntegers($denominator, (string) $q));
            }
        }

        return [ltrim($quotient, '0') ?: '0', $remainder];
    }
}
