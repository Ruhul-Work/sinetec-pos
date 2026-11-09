<?php
namespace App\Services\Purchase;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Purchase\PurchaseReceiptStatus;
use App\Enums\Purchase\PurchaseReturnStatus;
use App\Exceptions\Purchase\InvalidPurchaseReturnException;
use App\Models\Purchase\PurchaseReceipt;
use App\Models\Purchase\PurchaseReceiptItem;
use App\Models\Purchase\PurchaseReturn;
use App\Services\Finance\PurchaseJournalService;
use App\Services\Inventory\PartStockPostingService;
use App\Services\Inventory\RawMaterialStockPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PurchaseReturnService
{
    public function __construct(
        private readonly RawMaterialStockPostingService $rawMaterialStock,
        private readonly PartStockPostingService $partStock,
        private readonly PurchaseJournalService $purchaseJournal,
    ) {}

    /** @param array<string, mixed> $header @param array<int, array<string, mixed>> $lines */
    public function createDraft(array $header, array $lines, ?int $userId = null): PurchaseReturn
    {
        return DB::transaction(function () use ($header, $lines, $userId) {
            $receipt                   = $this->receipt($header);
            [$normalizedLines, $total] = $this->normalize($lines, $receipt);

            $return = PurchaseReturn::query()->create([
                'return_no'           => $this->nextNumber(),
                'purchase_receipt_id' => $receipt->id,
                'branch_id'           => $receipt->branch_id,
                'supplier_id'         => $receipt->supplier_id,
                'warehouse_id'        => $receipt->warehouse_id,
                'return_date'         => $header['return_date'],
                'status'              => PurchaseReturnStatus::Draft,
                'total'               => $total,
                'reason'              => trim((string) $header['reason']),
                'created_by'          => $userId,
            ]);

            $this->insertLines($return, $normalizedLines);

            return $this->load($return);
        }, 3);
    }

    /** @param array<string, mixed> $header @param array<int, array<string, mixed>> $lines */
    public function updateDraft(PurchaseReturn $return, array $header, array $lines): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $header, $lines) {
            $return = $this->lock($return);
            $this->assertDraft($return);
            $receipt = $this->receipt($header);
            if ((int) $return->purchase_receipt_id !== (int) $receipt->id) {
                throw new InvalidPurchaseReturnException('The source receipt of an existing purchase return cannot be changed.');
            }
            [$normalizedLines, $total] = $this->normalize($lines, $receipt);

            $return->update([
                'return_date' => $header['return_date'],
                'total'       => $total,
                'reason'      => trim((string) $header['reason']),
            ]);
            $return->items()->delete();
            $this->insertLines($return, $normalizedLines);

            return $this->load($return);
        }, 3);
    }

    public function post(PurchaseReturn $return, ?int $userId = null): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $userId) {
            $return = $this->lock($return);
            if ($return->status === PurchaseReturnStatus::Posted) {
                $this->purchaseJournal->createReturnDraft($return, $userId);

                return $this->load($return);
            }
            $this->assertDraft($return);

            $receipt = PurchaseReceipt::query()
                ->whereKey($return->purchase_receipt_id)
                ->where('status', PurchaseReceiptStatus::Posted)
                ->lockForUpdate()
                ->first();
            if (! $receipt) {
                throw new InvalidPurchaseReturnException('The source purchase receipt is not posted or is unavailable.');
            }

            $items = $return->items()->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw new InvalidPurchaseReturnException('At least one return line is required before posting.');
            }

            $postedAt   = now();
            $movementAt = $return->return_date->setTime($postedAt->hour, $postedAt->minute, $postedAt->second);

            foreach ($items as $item) {
                $source = PurchaseReceiptItem::query()
                    ->whereKey($item->purchase_receipt_item_id)
                    ->where('purchase_receipt_id', $receipt->id)
                    ->lockForUpdate()
                    ->first();
                if (! $source || (int) ($source->raw_material_id ?: $source->part_id) !== (int) ($item->raw_material_id ?: $item->part_id)) {
                    throw new InvalidPurchaseReturnException('A return item does not match its source receipt line.');
                }

                $available = $this->availableBaseQuantity($source);
                if ($this->compareDecimal((string) $item->base_quantity, $available, 6) > 0) {
                    throw new InvalidPurchaseReturnException('A return quantity exceeds the currently returnable receipt quantity.');
                }

                $domain  = $item->raw_material_id ? 'raw_material' : 'part';
                $itemId  = (int) ($item->raw_material_id ?: $item->part_id);
                $posting = new StockPostingData(
                    itemId: $itemId,
                    branchId: (int) $return->branch_id,
                    warehouseId: (int) $return->warehouse_id,
                    movementType: StockMovementType::PurchaseReturn,
                    direction: StockDirection::Out,
                    quantity: $item->base_quantity,
                    sourceType: 'purchase_return',
                    sourceId: (int) $return->id,
                    postingKey: 'purchase_return:' . $return->id . ':item:' . $item->id,
                    movementAt: $movementAt,
                    unitCost: $this->divideDecimal((string) $item->unit_rate, (string) $item->conversion_to_base, 4),
                    sourceItemId: (int) $item->id,
                    note: 'Return against receipt ' . $receipt->receipt_no,
                    postedBy: $userId,
                );

                ($domain === 'raw_material' ? $this->rawMaterialStock : $this->partStock)->post($posting);
            }

            $this->purchaseJournal->createReturnDraft($return, $userId);

            $return->update([
                'status'    => PurchaseReturnStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => $postedAt,
            ]);

            return $this->load($return);
        }, 3);
    }

    public function deleteDraft(PurchaseReturn $return): void
    {
        DB::transaction(function () use ($return) {
            $return = $this->lock($return);
            $this->assertDraft($return);
            $return->delete();
        }, 3);
    }

    public function availableBaseQuantity(PurchaseReceiptItem $source): string
    {
        $returned   = '0.000000';
        $quantities = DB::table('purchase_return_item as pri')
            ->join('purchase_return as pr', 'pr.id', '=', 'pri.purchase_return_id')
            ->where('pri.purchase_receipt_item_id', $source->id)
            ->where('pr.status', PurchaseReturnStatus::Posted->value)
            ->pluck('pri.base_quantity');
        foreach ($quantities as $quantity) {
            $returned = $this->addDecimal($returned, $this->decimal($quantity, 6, 14, true, 'returned quantity'), 6);
        }

        $received = (string) $source->base_quantity;
        if ($this->compareDecimal($returned, $received, 6) >= 0) {
            return '0.000000';
        }

        return $this->subtractDecimal($received, $returned, 6);
    }

    /** @param array<string, mixed> $header */
    private function receipt(array $header): PurchaseReceipt
    {
        $receipt = PurchaseReceipt::query()
            ->whereKey((int) ($header['purchase_receipt_id'] ?? 0))
            ->where('status', PurchaseReceiptStatus::Posted)
            ->lockForUpdate()
            ->with(['items.rawMaterial.baseUnit', 'items.part.baseUnit', 'items.unit'])
            ->first();
        if (! $receipt) {
            throw new InvalidPurchaseReturnException('Only a posted purchase receipt can be returned.');
        }
        if ((string) $header['return_date'] < $receipt->receipt_date->format('Y-m-d')) {
            throw new InvalidPurchaseReturnException('Return date cannot be before the source receipt date.');
        }

        return $receipt;
    }

    /** @param array<int, array<string, mixed>> $lines @return array{array<int, array<string, mixed>>, string} */
    private function normalize(array $lines, PurchaseReceipt $receipt): array
    {
        if ($lines === []) {
            throw new InvalidPurchaseReturnException('At least one purchase return line is required.');
        }

        $sources    = $receipt->items->keyBy('id');
        $normalized = [];
        $seen       = [];
        $total      = '0.0000';
        foreach ($lines as $index => $line) {
            $sourceId = (int) ($line['purchase_receipt_item_id'] ?? 0);
            /** @var PurchaseReceiptItem|null $source */
            $source = $sources->get($sourceId);
            if (! $source) {
                throw new InvalidPurchaseReturnException('Line ' . ($index + 1) . ' is not part of the selected purchase receipt.');
            }
            if (isset($seen[$sourceId])) {
                throw new InvalidPurchaseReturnException('The same receipt line cannot be returned more than once in one document.');
            }
            $seen[$sourceId] = true;

            $quantity     = $this->decimal($line['quantity'] ?? '', 6, 14, false, 'return quantity');
            $baseQuantity = $this->multiplyDecimal($quantity, (string) $source->conversion_to_base, 6);
            $available    = $this->availableBaseQuantity($source);
            if ($this->compareDecimal($baseQuantity, $available, 6) > 0) {
                throw new InvalidPurchaseReturnException('Line ' . ($index + 1) . ' exceeds the currently returnable receipt quantity.');
            }
            $unitRate     = $this->decimal($source->unit_rate, 4, 16, false, 'source unit rate');
            $lineTotal    = $this->multiplyDecimal($quantity, $unitRate, 4);
            $total        = $this->addDecimal($total, $lineTotal, 4);
            $normalized[] = [
                'purchase_receipt_item_id' => $source->id,
                'raw_material_id'          => $source->raw_material_id,
                'part_id'                  => $source->part_id,
                'unit_id'                  => $source->unit_id,
                'conversion_to_base'       => $source->conversion_to_base,
                'quantity'                 => $quantity,
                'base_quantity'            => $baseQuantity,
                'unit_rate'                => $unitRate,
                'line_total'               => $lineTotal,
            ];
        }

        return [$normalized, $total];
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function insertLines(PurchaseReturn $return, array $lines): void
    {
        $now = now();
        foreach ($lines as $line) {
            DB::table('purchase_return_item')->insert([
                'purchase_return_id' => $return->id,
                ...$line,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }
    }

    private function lock(PurchaseReturn $return): PurchaseReturn
    {
        return PurchaseReturn::query()->whereKey($return->id)->lockForUpdate()->first() ?? throw new InvalidPurchaseReturnException('The purchase return does not exist.');
    }

    private function assertDraft(PurchaseReturn $return): void
    {
        if ($return->status !== PurchaseReturnStatus::Draft) {
            throw new InvalidPurchaseReturnException('Only a draft purchase return can be changed or posted.');
        }
    }

    private function load(PurchaseReturn $return): PurchaseReturn
    {
        return $return->fresh([
            'receipt', 'branch', 'supplier', 'warehouse', 'createdBy', 'postedBy',
            'items.receiptItem', 'items.rawMaterial.baseUnit', 'items.part.baseUnit', 'items.unit',
        ]);
    }

    private function nextNumber(): string
    {
        do {
            $number = 'PRT-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8));
        } while (PurchaseReturn::query()->where('return_no', $number)->exists());

        return $number;
    }

    private function decimal(mixed $value, int $scale, int $maxIntegerDigits, bool $allowZero, string $field): string
    {
        $raw = is_float($value) ? rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.') : trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw new InvalidPurchaseReturnException("The {$field} must be a non-negative decimal number.");
        }
        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer              = ltrim($integer, '0') ?: '0';
        $fraction             = rtrim($fraction, '0');
        if (strlen($integer) > $maxIntegerDigits || strlen($fraction) > $scale) {
            throw new InvalidPurchaseReturnException("The {$field} exceeds the supported precision.");
        }
        $normalized = $integer . '.' . str_pad($fraction, $scale, '0');
        if (! $allowZero && $normalized === '0.' . str_repeat('0', $scale)) {
            throw new InvalidPurchaseReturnException("The {$field} must be greater than zero.");
        }

        return $normalized;
    }

    private function multiplyDecimal(string $left, string $right, int $scale): string
    {
        [$li, $lf]   = array_pad(explode('.', $left, 2), 2, '');
        [$ri, $rf]   = array_pad(explode('.', $right, 2), 2, '');
        $product     = $this->multiplyIntegers(ltrim($li . $lf, '0') ?: '0', ltrim($ri . $rf, '0') ?: '0');
        $sourceScale = strlen($lf) + strlen($rf);
        if ($sourceScale > $scale) {
            $cut        = $sourceScale - $scale;
            $roundDigit = strlen($product) > $cut ? (int) $product[strlen($product) - $cut] : 0;
            $product    = strlen($product) > $cut ? substr($product, 0, -$cut) : '0';
            if ($roundDigit >= 5) {
                $product = $this->addIntegers($product, '1');
            }
            $sourceScale = $scale;
        }

        return $this->integerToDecimal($product, $sourceScale, $scale);
    }

    private function divideDecimal(string $left, string $right, int $scale): string
    {
        $left                   = $this->decimal($left, 4, 16, true, 'unit rate');
        $right                  = $this->decimal($right, 6, 14, false, 'conversion factor');
        $numerator              = (ltrim($this->digits($left, 4), '0') ?: '0') . str_repeat('0', 6 - 4 + $scale);
        $denominator            = ltrim($this->digits($right, 6), '0') ?: '0';
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
        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');

        return (ltrim($integer, '0') ?: '0') . substr(str_pad($fraction, $scale, '0'), 0, $scale);
    }

    private function integerToDecimal(string $digits, int $sourceScale, int $targetScale): string
    {
        $digits   = ltrim($digits, '0') ?: '0';
        $digits   = str_pad($digits, $sourceScale + 1, '0', STR_PAD_LEFT);
        $integer  = $sourceScale === 0 ? $digits : substr($digits, 0, -$sourceScale);
        $fraction = $sourceScale === 0 ? '' : substr($digits, -$sourceScale);

        return (ltrim($integer, '0') ?: '0') . '.' . str_pad($fraction, $targetScale, '0');
    }

    private function compareIntegers(string $left, string $right): int
    {
        $left  = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private function addIntegers(string $left, string $right): string
    {
        $carry  = 0;
        $result = '';
        for ($i = strlen($left) - 1, $j = strlen($right) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
            $sum    = ($i >= 0 ? (int) $left[$i] : 0) + ($j >= 0 ? (int) $right[$j] : 0) + $carry;
            $result = ($sum % 10) . $result;
            $carry  = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private function subtractIntegers(string $left, string $right): string
    {
        $left   = str_pad($left, max(strlen($left), strlen($right)), '0', STR_PAD_LEFT);
        $right  = str_pad($right, strlen($left), '0', STR_PAD_LEFT);
        $borrow = 0;
        $result = '';
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            $digit = (int) $left[$i] - $borrow - (int) $right[$i];
            if ($digit < 0) {
                $digit  += 10;
                $borrow  = 1;
            } else {
                $borrow = 0;
            }
            $result = $digit . $result;
        }

        return ltrim($result, '0') ?: '0';
    }

    private function multiplyIntegers(string $left, string $right): string
    {
        $result = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $position               = $i + $j + 1;
                $sum                    = $result[$position] + ((int) $left[$i] * (int) $right[$j]);
                $result[$position]      = $sum % 10;
                $result[$position - 1] += intdiv($sum, 10);
            }
        }

        return ltrim(implode('', $result), '0') ?: '0';
    }

    /** @return array{string, string} */
    private function divideIntegers(string $numerator, string $denominator): array
    {
        $quotient  = '';
        $remainder = '0';
        foreach (str_split(ltrim($numerator, '0') ?: '0') as $digit) {
            $remainder     = ltrim($remainder . $digit, '0') ?: '0';
            $quotientDigit = 0;
            for ($candidate = 9; $candidate >= 1; $candidate--) {
                if ($this->compareIntegers($this->multiplyIntegers($denominator, (string) $candidate), $remainder) <= 0) {
                    $quotientDigit = $candidate;
                    break;
                }
            }
            $quotient .= $quotientDigit;
            if ($quotientDigit) {
                $remainder = $this->subtractIntegers($remainder, $this->multiplyIntegers($denominator, (string) $quotientDigit));
            }
        }

        return [ltrim($quotient, '0') ?: '0', $remainder];
    }
}
