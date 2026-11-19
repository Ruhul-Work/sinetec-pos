<?php

namespace App\Services\Sales;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Sales\SaleReturnStatus;
use App\Enums\Sales\SaleStatus;
use App\Exceptions\Sales\InvalidSaleReturnException;
use App\Models\backend\Account;
use App\Models\Sales\Sale;
use App\Models\Sales\SaleItem;
use App\Models\Sales\SaleReturn;
use App\Services\Finance\SaleReturnJournalService;
use App\Services\Inventory\FinishedProductStockPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SaleReturnService
{
    public function __construct(
        private readonly FinishedProductStockPostingService $stock,
        private readonly SaleReturnJournalService $journals,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createDraft(array $header, array $items, ?int $userId): SaleReturn
    {
        return DB::transaction(function () use ($header, $items, $userId): SaleReturn {
            $sale = $this->sourceSale($header);
            [$normalizedItems, $pricing] = $this->normalizeItems($items, $sale);

            $saleReturn = SaleReturn::query()->create([
                'return_no' => $this->nextNumber(),
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'warehouse_id' => $sale->warehouse_id,
                'return_at' => $header['return_at'],
                'status' => SaleReturnStatus::Draft,
                'subtotal' => $pricing['subtotal'],
                'discount' => $pricing['discount'],
                'tax' => $pricing['tax'],
                'total' => $pricing['total'],
                'reason' => trim((string) $header['reason']),
                'created_by' => $userId,
            ]);

            $saleReturn->items()->createMany($normalizedItems);

            return $this->load($saleReturn);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $items
     */
    public function updateDraft(SaleReturn $saleReturn, array $header, array $items): SaleReturn
    {
        return DB::transaction(function () use ($saleReturn, $header, $items): SaleReturn {
            $saleReturn = $this->lock($saleReturn);
            $this->assertDraft($saleReturn);

            $sale = $this->sourceSale($header);
            if ((int) $saleReturn->sale_id !== (int) $sale->id) {
                throw new InvalidSaleReturnException('The source Sale of an existing return cannot be changed.');
            }

            [$normalizedItems, $pricing] = $this->normalizeItems($items, $sale);

            $saleReturn->update([
                'return_at' => $header['return_at'],
                'subtotal' => $pricing['subtotal'],
                'discount' => $pricing['discount'],
                'tax' => $pricing['tax'],
                'total' => $pricing['total'],
                'reason' => trim((string) $header['reason']),
            ]);

            $saleReturn->items()->delete();
            $saleReturn->items()->createMany($normalizedItems);

            return $this->load($saleReturn);
        }, 3);
    }

    public function deleteDraft(SaleReturn $saleReturn): void
    {
        DB::transaction(function () use ($saleReturn): void {
            $saleReturn = $this->lock($saleReturn);
            $this->assertDraft($saleReturn);
            $saleReturn->delete();
        }, 3);
    }

    /**
     * @return array{subtotal: string, discount: string, tax: string, total: string,
     *     due_adjustment: string, refund_amount: string}
     */
    public function postingSummary(SaleReturn $saleReturn): array
    {
        $saleReturn->loadMissing(['sale', 'items']);
        $subtotal = $this->sumAmounts($saleReturn->items->pluck('line_total')->all());
        $pricing = $this->pricing($subtotal, $saleReturn->sale);
        $settlement = $this->settlement(
            $pricing['total'],
            (string) $saleReturn->sale->due_amount,
            (string) $saleReturn->sale->paid_amount,
        );

        return [...$pricing, ...$settlement];
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     */
    public function post(SaleReturn $saleReturn, array $payments, int $userId): SaleReturn
    {
        return DB::transaction(function () use ($saleReturn, $payments, $userId): SaleReturn {
            $saleReturn = SaleReturn::query()
                ->with(['items', 'sale'])
                ->whereKey($saleReturn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($saleReturn->status === SaleReturnStatus::Posted) {
                return $this->load($saleReturn);
            }
            $this->assertDraft($saleReturn);
            $this->assertUser($userId);

            $sale = Sale::query()
                ->with(['items.finishedProduct.baseUnit', 'items.unit'])
                ->whereKey($saleReturn->sale_id)
                ->where('status', SaleStatus::Posted)
                ->lockForUpdate()
                ->first();
            if (! $sale) {
                throw new InvalidSaleReturnException(
                    'The source Sale must remain Posted before its return can be posted.',
                );
            }

            $draftItems = $saleReturn->items->map(fn ($item): array => [
                'sale_item_id' => $item->sale_item_id,
                'quantity' => $item->quantity,
            ])->all();
            [$normalizedItems, $pricing] = $this->normalizeItems($draftItems, $sale);

            $saleReturn->items()->delete();
            $saleReturn->items()->createMany($normalizedItems);
            $saleReturn->update([
                'subtotal' => $pricing['subtotal'],
                'discount' => $pricing['discount'],
                'tax' => $pricing['tax'],
                'total' => $pricing['total'],
            ]);
            $saleReturn->refresh();

            $settlement = $this->settlement(
                $pricing['total'],
                (string) $sale->due_amount,
                (string) $sale->paid_amount,
            );
            $normalizedPayments = $this->refundPayments(
                $payments,
                (int) $saleReturn->branch_id,
                $settlement['refund_amount'],
            );

            foreach ($saleReturn->items()->with('saleItem')->get() as $returnItem) {
                $this->stock->post(new StockPostingData(
                    itemId: (int) $returnItem->finished_product_id,
                    branchId: (int) $saleReturn->branch_id,
                    warehouseId: (int) $saleReturn->warehouse_id,
                    movementType: StockMovementType::SaleReturn,
                    direction: StockDirection::In,
                    quantity: $returnItem->base_quantity,
                    sourceType: 'sale_return',
                    sourceId: (int) $saleReturn->id,
                    postingKey: 'stock:sale-return:'.$saleReturn->id.':item:'.$returnItem->id,
                    movementAt: $saleReturn->return_at,
                    unitCost: $returnItem->saleItem->unit_cost ?? '0.0000',
                    sourceItemId: (int) $returnItem->id,
                    note: 'Sales Return '.$saleReturn->return_no,
                    postedBy: $userId,
                ));
            }

            $saleReturn->payments()->delete();
            foreach ($normalizedPayments as $payment) {
                $saleReturn->payments()->create([
                    ...$payment,
                    'created_by' => $userId,
                ]);
            }

            $sale->update([
                'paid_amount' => bcsub(
                    (string) $sale->paid_amount,
                    $settlement['refund_amount'],
                    4,
                ),
                'due_amount' => bcsub(
                    (string) $sale->due_amount,
                    $settlement['due_adjustment'],
                    4,
                ),
            ]);
            $saleReturn->update([
                'status' => SaleReturnStatus::Posted,
                'due_adjustment' => $settlement['due_adjustment'],
                'refund_amount' => $settlement['refund_amount'],
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            $saleReturn = $this->load($saleReturn);
            $this->journals->createAndPost($saleReturn, $userId);

            return $this->load($saleReturn);
        }, 3);
    }

    public function availableBaseQuantity(SaleItem $saleItem): string
    {
        $postedReturnQuantity = DB::table('sale_return_items as return_item')
            ->join('sale_returns as sale_return', 'sale_return.id', '=', 'return_item.sale_return_id')
            ->where('return_item.sale_item_id', $saleItem->id)
            ->where('sale_return.status', SaleReturnStatus::Posted->value)
            ->sum('return_item.base_quantity');

        $available = bcsub(
            $this->decimal($saleItem->base_quantity, 6, 14, true, 'sold base quantity'),
            $this->decimal($postedReturnQuantity, 6, 14, true, 'returned base quantity'),
            6,
        );

        return bccomp($available, '0.000000', 6) === 1 ? $available : '0.000000';
    }

    /** @param array<string, mixed> $header */
    private function sourceSale(array $header): Sale
    {
        $sale = Sale::query()
            ->whereKey((int) ($header['sale_id'] ?? 0))
            ->where('status', SaleStatus::Posted)
            ->with(['items.finishedProduct.baseUnit', 'items.unit'])
            ->lockForUpdate()
            ->first();

        if (! $sale) {
            throw new InvalidSaleReturnException('Only a Posted POS or Wholesale Sale can be returned.');
        }

        $activeBranchId = current_branch_id();
        if ($activeBranchId !== null && (int) $sale->branch_id !== $activeBranchId) {
            throw new InvalidSaleReturnException('The selected Sale is outside the active branch.');
        }

        if ((string) $header['return_at'] < $sale->sale_at->format('Y-m-d\TH:i')) {
            throw new InvalidSaleReturnException('Return date and time cannot be before the source Sale.');
        }

        return $sale;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: array<int, array<string, mixed>>, 1: array{subtotal: string, discount: string, tax: string, total: string}}
     */
    private function normalizeItems(array $items, Sale $sale): array
    {
        if ($items === []) {
            throw new InvalidSaleReturnException('Add at least one item to the Sales Return.');
        }

        $sourceItems = $sale->items->keyBy('id');
        $seenItems = [];
        $normalizedItems = [];
        $subtotal = '0.0000';

        foreach ($items as $index => $item) {
            $lineNumber = $index + 1;
            $sourceItemId = (int) ($item['sale_item_id'] ?? 0);

            /** @var SaleItem|null $sourceItem */
            $sourceItem = $sourceItems->get($sourceItemId);
            if (! $sourceItem) {
                throw new InvalidSaleReturnException("Line {$lineNumber} is not part of the selected Sale.");
            }

            if (isset($seenItems[$sourceItemId])) {
                throw new InvalidSaleReturnException('The same Sale line cannot be returned more than once in one document.');
            }
            $seenItems[$sourceItemId] = true;

            $quantity = $this->decimal(
                $item['quantity'] ?? null,
                6,
                14,
                false,
                "line {$lineNumber} return quantity",
            );
            $conversion = $this->decimal(
                $sourceItem->conversion_to_base,
                6,
                14,
                false,
                "line {$lineNumber} unit conversion",
            );
            $baseQuantity = bcmul($quantity, $conversion, 6);
            $available = $this->availableBaseQuantity($sourceItem);

            if (bccomp($baseQuantity, $available, 6) === 1) {
                throw new InvalidSaleReturnException(
                    "Line {$lineNumber} exceeds the currently returnable Sale quantity.",
                );
            }

            $unitPrice = $this->decimal(
                $sourceItem->unit_price,
                4,
                16,
                true,
                "line {$lineNumber} original unit price",
            );
            $lineTotal = bcmul($quantity, $unitPrice, 4);
            $subtotal = bcadd($subtotal, $lineTotal, 4);

            $normalizedItems[] = [
                'sale_item_id' => $sourceItem->id,
                'finished_product_id' => $sourceItem->finished_product_id,
                'unit_id' => $sourceItem->unit_id,
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        return [$normalizedItems, $this->pricing($subtotal, $sale)];
    }

    /** @return array{subtotal: string, discount: string, tax: string, total: string} */
    private function pricing(string $subtotal, Sale $sale): array
    {
        $saleSubtotal = (string) $sale->subtotal;
        if (bccomp($saleSubtotal, '0', 4) !== 1) {
            throw new InvalidSaleReturnException(
                'The source Sale subtotal is invalid and cannot be allocated to a return.',
            );
        }
        if (bccomp($subtotal, $saleSubtotal, 4) === 1) {
            throw new InvalidSaleReturnException(
                'The Sales Return subtotal cannot exceed the source Sale subtotal.',
            );
        }

        $discount = $this->proportionalAmount(
            (string) $sale->discount,
            $subtotal,
            $saleSubtotal,
        );
        $tax = $this->proportionalAmount(
            (string) $sale->tax,
            $subtotal,
            $saleSubtotal,
        );
        $total = bcadd(bcsub($subtotal, $discount, 4), $tax, 4);

        return compact('subtotal', 'discount', 'tax', 'total');
    }

    private function proportionalAmount(
        string $sourceAmount,
        string $portion,
        string $whole,
    ): string {
        if (bccomp($sourceAmount, '0', 4) !== 1) {
            return '0.0000';
        }
        if (bccomp($portion, $whole, 4) === 0) {
            return number_format((float) $sourceAmount, 4, '.', '');
        }

        return bcdiv(bcmul($sourceAmount, $portion, 8), $whole, 4);
    }

    /** @return array{due_adjustment: string, refund_amount: string} */
    private function settlement(
        string $returnTotal,
        string $currentDue,
        string $currentPaid,
    ): array {
        $unreturnedSaleValue = bcadd($currentDue, $currentPaid, 4);
        if (bccomp($returnTotal, $unreturnedSaleValue, 4) === 1) {
            throw new InvalidSaleReturnException(
                'The return value exceeds the remaining paid and due value of the source Sale.',
            );
        }

        $dueAdjustment = bccomp($returnTotal, $currentDue, 4) === 1
            ? $currentDue
            : $returnTotal;

        return [
            'due_adjustment' => $dueAdjustment,
            'refund_amount' => bcsub($returnTotal, $dueAdjustment, 4),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     * @return array<int, array<string, mixed>>
     */
    private function refundPayments(
        array $payments,
        int $branchId,
        string $refundAmount,
    ): array {
        if (bccomp($refundAmount, '0', 4) === 0) {
            if ($payments !== []) {
                throw new InvalidSaleReturnException(
                    'No customer refund is required because the return is fully adjusting due.',
                );
            }

            return [];
        }
        if ($payments === []) {
            throw new InvalidSaleReturnException(
                'Add at least one refund method for the amount payable to the customer.',
            );
        }

        $normalized = [];
        $paymentTotal = '0.0000';

        foreach ($payments as $index => $payment) {
            $paymentTypeId = (int) ($payment['payment_type_id'] ?? 0);
            $accountId = (int) ($payment['account_id'] ?? 0);
            $amount = $this->amount(
                $payment['amount'] ?? null,
                'refund row '.($index + 1),
            );

            $this->paymentType($paymentTypeId);
            $this->cashAccount($accountId, $branchId);
            $paymentTotal = bcadd($paymentTotal, $amount, 4);

            $normalized[] = [
                'payment_type_id' => $paymentTypeId,
                'account_id' => $accountId,
                'amount' => $amount,
                'reference_no' => $this->nullableText($payment['reference_no'] ?? null),
            ];
        }

        if (bccomp($paymentTotal, $refundAmount, 4) !== 0) {
            throw new InvalidSaleReturnException(
                'Refund method total must exactly match the customer refund amount.',
            );
        }

        return $normalized;
    }

    private function paymentType(int $paymentTypeId): void
    {
        $exists = DB::table('payment_types')
            ->where('id', $paymentTypeId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw new InvalidSaleReturnException(
                'Select an active Payment Method for every refund row.',
            );
        }
    }

    private function cashAccount(int $accountId, int $branchId): void
    {
        $account = Account::query()
            ->with('type')
            ->whereKey($accountId)
            ->where('is_active', true)
            ->first();
        $assigned = DB::table('branch_accounts')
            ->where('branch_id', $branchId)
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->exists();

        if (! $account
            || ! $account->is_cash_bank_account
            || $account->type?->code !== 'ASSET'
            || ! $assigned) {
            throw new InvalidSaleReturnException(
                'Choose an active Cash, Bank or Mobile Financial account assigned to the return branch.',
            );
        }
    }

    private function amount(mixed $value, string $field): string
    {
        $raw = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $raw)) {
            throw new InvalidSaleReturnException("The {$field} amount is invalid.");
        }

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $normalized = (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, 4, '0');
        if (bccomp($normalized, '0', 4) !== 1) {
            throw new InvalidSaleReturnException("The {$field} amount must be greater than zero.");
        }

        return $normalized;
    }

    private function assertUser(int $userId): void
    {
        if ($userId < 1 || ! DB::table('users')->where('id', $userId)->exists()) {
            throw new InvalidSaleReturnException(
                'An active operator is required to post the Sales Return.',
            );
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** @param array<int, mixed> $amounts */
    private function sumAmounts(array $amounts): string
    {
        $total = '0.0000';
        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, 4);
        }

        return $total;
    }

    private function assertDraft(SaleReturn $saleReturn): void
    {
        if ($saleReturn->status !== SaleReturnStatus::Draft) {
            throw new InvalidSaleReturnException('Only a Draft Sales Return can be changed or deleted.');
        }
    }

    private function lock(SaleReturn $saleReturn): SaleReturn
    {
        return SaleReturn::query()
            ->lockForUpdate()
            ->find($saleReturn->id)
            ?? throw new InvalidSaleReturnException('The Sales Return does not exist.');
    }

    private function load(SaleReturn $saleReturn): SaleReturn
    {
        return $saleReturn->fresh([
            'sale.customer',
            'branch',
            'warehouse',
            'items.saleItem',
            'items.finishedProduct.baseUnit',
            'items.unit',
            'payments.paymentType',
            'payments.account',
            'createdBy',
            'postedBy',
        ]);
    }

    private function nextNumber(): string
    {
        do {
            $number = 'SRT-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (SaleReturn::query()->where('return_no', $number)->exists());

        return $number;
    }

    private function decimal(
        mixed $value,
        int $scale,
        int $maxIntegerDigits,
        bool $allowZero,
        string $field,
    ): string {
        $raw = is_float($value)
            ? rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.')
            : trim((string) $value);

        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw new InvalidSaleReturnException("The {$field} must be a non-negative decimal number.");
        }

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        if (strlen($integer) > $maxIntegerDigits || strlen($fraction) > $scale) {
            throw new InvalidSaleReturnException("The {$field} exceeds the supported precision.");
        }

        $normalized = $integer.'.'.str_pad($fraction, $scale, '0');
        if (! $allowZero && bccomp($normalized, '0', $scale) === 0) {
            throw new InvalidSaleReturnException("The {$field} must be greater than zero.");
        }

        return $normalized;
    }
}
