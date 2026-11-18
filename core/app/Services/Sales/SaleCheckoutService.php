<?php

namespace App\Services\Sales;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Sales\SaleStatus;
use App\Exceptions\Sales\InvalidSaleCheckoutException;
use App\Models\backend\Account;
use App\Models\Sales\Sale;
use App\Models\Sales\SalePayment;
use App\Services\Finance\SalesJournalService;
use App\Services\Inventory\FinishedProductStockPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SaleCheckoutService
{
    public function __construct(
        private readonly FinishedProductStockPostingService $stock,
        private readonly SalesJournalService $journals,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $payments
     */
    public function prepare(Sale $sale, array $payments, int $userId): Sale
    {
        return DB::transaction(function () use ($sale, $payments, $userId): Sale {
            $sale = Sale::query()
                ->with(['items', 'payments'])
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sale->status !== SaleStatus::Draft) {
                throw new InvalidSaleCheckoutException('Only a Draft Sale can be prepared for posting.');
            }
            if ($sale->items->isEmpty()) {
                throw new InvalidSaleCheckoutException('Add at least one Finished Product before review.');
            }
            $this->assertUser($userId);

            [$normalizedPayments, $paidAmount] = $this->payments(
                $payments,
                (int) $sale->branch_id,
                (string) $sale->total,
            );
            $dueAmount = bcsub((string) $sale->total, $paidAmount, 4);

            if ($sale->customer_id === null && bccomp($dueAmount, '0', 4) === 1) {
                throw new InvalidSaleCheckoutException(
                    'Walk-in Customer cannot keep a due balance. Select a registered Customer or collect full payment.',
                );
            }
            if ($sale->payments->contains(fn (SalePayment $payment): bool => $payment->status !== 'DRAFT')) {
                throw new InvalidSaleCheckoutException('A Draft Sale cannot replace an already posted payment.');
            }

            $sale->payments()->delete();
            foreach ($normalizedPayments as $payment) {
                SalePayment::query()->create([
                    'payment_no' => $this->nextPaymentNumber(),
                    'sale_id' => $sale->id,
                    'payment_type_id' => $payment['payment_type_id'],
                    'account_id' => $payment['account_id'],
                    'payment_at' => $sale->sale_at,
                    'amount' => $payment['amount'],
                    'tendered_amount' => $payment['tendered_amount'],
                    'change_amount' => $payment['change_amount'],
                    'reference_no' => $payment['reference_no'],
                    'status' => 'DRAFT',
                    'created_by' => $userId,
                ]);
            }

            $sale->update([
                'status' => SaleStatus::ReadyToPost,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'posted_by' => null,
                'posted_at' => null,
            ]);

            return $this->load($sale);
        }, 3);
    }

    public function post(Sale $sale, int $userId): Sale
    {
        return DB::transaction(function () use ($sale, $userId): Sale {
            $sale = Sale::query()
                ->with(['items', 'payments'])
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sale->status === SaleStatus::Posted) {
                return $this->load($sale);
            }
            if ($sale->status !== SaleStatus::ReadyToPost) {
                throw new InvalidSaleCheckoutException('Only a Ready to Post Sale can be confirmed.');
            }
            if ($sale->items->isEmpty()) {
                throw new InvalidSaleCheckoutException('The Sale has no Finished Product to post.');
            }
            $this->assertUser($userId);
            $this->assertPreparedPayments($sale);

            foreach ($sale->items as $item) {
                $unitCost = $this->latestUnitCost(
                    (int) $item->finished_product_id,
                    (int) $sale->warehouse_id,
                );

                $this->stock->post(new StockPostingData(
                    itemId: (int) $item->finished_product_id,
                    branchId: (int) $sale->branch_id,
                    warehouseId: (int) $sale->warehouse_id,
                    movementType: StockMovementType::Sale,
                    direction: StockDirection::Out,
                    quantity: $item->base_quantity,
                    sourceType: 'sale',
                    sourceId: (int) $sale->id,
                    postingKey: 'stock:sale:'.$sale->id.':item:'.$item->id,
                    movementAt: $sale->sale_at,
                    unitCost: $unitCost,
                    sourceItemId: (int) $item->id,
                    note: $sale->channel->label().' sale '.$sale->sale_no,
                    postedBy: $userId,
                ));

                $item->update(['unit_cost' => $unitCost]);
            }

            $sale->payments()->where('status', 'DRAFT')->update(['status' => 'POSTED']);
            $sale->update([
                'status' => SaleStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);
            $sale->refresh();
            $postedPayments = $sale->payments()->where('status', 'POSTED')->get();

            $this->journals->createAndPost($sale, $postedPayments, $userId);

            return $this->load($sale);
        }, 3);
    }

    public function reopen(Sale $sale): Sale
    {
        return DB::transaction(function () use ($sale): Sale {
            $sale = Sale::query()
                ->with('payments')
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sale->status !== SaleStatus::ReadyToPost) {
                throw new InvalidSaleCheckoutException('Only a Ready to Post Sale can return to editing.');
            }
            if ($sale->payments->contains(fn (SalePayment $payment): bool => $payment->status !== 'DRAFT')) {
                throw new InvalidSaleCheckoutException('Posted Sale payments cannot return to Draft.');
            }

            $sale->payments()->delete();
            $sale->update([
                'status' => SaleStatus::Draft,
                'paid_amount' => '0.0000',
                'due_amount' => $sale->total,
                'posted_by' => null,
                'posted_at' => null,
            ]);

            return $this->load($sale);
        }, 3);
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     * @return array{0: array<int, array<string, mixed>>, 1: string}
     */
    private function payments(array $payments, int $branchId, string $saleTotal): array
    {
        $normalized = [];
        $appliedTotal = '0.0000';

        foreach ($payments as $index => $payment) {
            $tenderedAmount = $this->amount($payment['amount'] ?? null, 'payment row '.($index + 1));
            $paymentTypeId = (int) ($payment['payment_type_id'] ?? 0);
            $accountId = (int) ($payment['account_id'] ?? 0);

            $paymentType = DB::table('payment_types')
                ->where('id', $paymentTypeId)
                ->where('is_active', true)
                ->first(['id', 'name', 'allows_change']);
            if (! $paymentType) {
                throw new InvalidSaleCheckoutException('Select an active Payment Method for every payment row.');
            }

            $this->cashAccount($accountId, $branchId);
            $remainingAmount = bcsub($saleTotal, $appliedTotal, 4);
            if (bccomp($remainingAmount, '0', 4) !== 1) {
                throw new InvalidSaleCheckoutException(
                    'Remove unused payment rows after the Sale total has been fully allocated.',
                );
            }

            $exceedsRemaining = bccomp($tenderedAmount, $remainingAmount, 4) === 1;
            if ($exceedsRemaining && ! $paymentType->allows_change) {
                throw new InvalidSaleCheckoutException(
                    "{$paymentType->name} cannot exceed the remaining Sale amount. Only a Cash-type payment may return change.",
                );
            }

            $appliedAmount = $exceedsRemaining ? $remainingAmount : $tenderedAmount;
            $changeAmount = $exceedsRemaining
                ? bcsub($tenderedAmount, $appliedAmount, 4)
                : '0.0000';

            $normalized[] = [
                'payment_type_id' => $paymentTypeId,
                'account_id' => $accountId,
                'amount' => $appliedAmount,
                'tendered_amount' => $tenderedAmount,
                'change_amount' => $changeAmount,
                'reference_no' => $this->nullableText($payment['reference_no'] ?? null),
            ];
            $appliedTotal = bcadd($appliedTotal, $appliedAmount, 4);
        }

        return [$normalized, $appliedTotal];
    }

    private function assertPreparedPayments(Sale $sale): void
    {
        $paidAmount = '0.0000';

        foreach ($sale->payments as $payment) {
            if ($payment->status !== 'DRAFT') {
                throw new InvalidSaleCheckoutException('The prepared Sale contains an invalid payment status.');
            }

            $paymentTypeExists = DB::table('payment_types')
                ->where('id', $payment->payment_type_id)
                ->where('is_active', true)
                ->exists();
            if (! $paymentTypeExists) {
                throw new InvalidSaleCheckoutException('A prepared Payment Method is no longer active.');
            }

            $this->cashAccount((int) $payment->account_id, (int) $sale->branch_id);
            $paidAmount = bcadd($paidAmount, (string) $payment->amount, 4);
        }

        if (bccomp($paidAmount, (string) $sale->paid_amount, 4) !== 0) {
            throw new InvalidSaleCheckoutException('The prepared payment total no longer matches the Sale.');
        }

        $expectedDue = bcsub((string) $sale->total, $paidAmount, 4);
        if (bccomp($expectedDue, (string) $sale->due_amount, 4) !== 0) {
            throw new InvalidSaleCheckoutException('The prepared due amount no longer matches the Sale.');
        }
        if ($sale->customer_id === null && bccomp($expectedDue, '0', 4) === 1) {
            throw new InvalidSaleCheckoutException('Walk-in Customer cannot post a Sale with due balance.');
        }
    }

    private function assertUser(int $userId): void
    {
        if (! DB::table('users')->where('id', $userId)->exists()) {
            throw new InvalidSaleCheckoutException('An active operator is required for the Sale workflow.');
        }
    }

    private function cashAccount(int $accountId, int $branchId): Account
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

        if (! $account || ! $account->is_cash_bank_account || $account->type?->code !== 'ASSET' || ! $assigned) {
            throw new InvalidSaleCheckoutException(
                'Choose an active Cash, Bank or Mobile Financial account assigned to the Sale branch.',
            );
        }

        return $account;
    }

    private function latestUnitCost(int $productId, int $warehouseId): string
    {
        $unitCost = DB::table('finished_product_stock_movements')
            ->where('finished_product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->whereNotNull('unit_cost')
            ->orderByDesc('movement_at')
            ->orderByDesc('id')
            ->value('unit_cost');

        return $unitCost === null ? '0.0000' : number_format((float) $unitCost, 4, '.', '');
    }

    private function amount(mixed $value, string $field): string
    {
        $raw = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $raw)) {
            throw new InvalidSaleCheckoutException("The {$field} amount is invalid.");
        }

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $normalized = (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, 4, '0');
        if (bccomp($normalized, '0', 4) !== 1) {
            throw new InvalidSaleCheckoutException("The {$field} amount must be greater than zero.");
        }

        return $normalized;
    }

    private function nextPaymentNumber(): string
    {
        do {
            $number = 'SPAY-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (SalePayment::query()->where('payment_no', $number)->exists());

        return $number;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function load(Sale $sale): Sale
    {
        return $sale->fresh([
            'branch',
            'warehouse',
            'customer',
            'items.finishedProduct.baseUnit',
            'items.unit',
            'payments.paymentType',
            'payments.account',
            'createdBy',
            'postedBy',
        ]);
    }
}
