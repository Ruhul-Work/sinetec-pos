<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ResetProductionTestBatch extends Command
{
    protected $signature = 'production:reset-test-batch
        {batch : Part production batch ID}
        {--execute : Apply the reset after the safety audit and snapshot}';

    protected $description = 'Reset one local production test batch and its generated stock/accounting data';

    private const LEGACY_PRODUCTION_ACCOUNT_CODES = [
        '5200',
        '5210',
        '5220',
        '5230',
        '5240',
        '5250',
    ];

    public function handle(): int
    {
        $this->assertSafeEnvironment();

        $batchId = (int) $this->argument('batch');
        $context = $this->batchContext($batchId);

        $this->displayAudit($context);

        if (! $this->option('execute')) {
            $this->warn('Dry run only. Re-run with --execute to create a snapshot and apply this exact reset.');

            return self::SUCCESS;
        }

        $this->assertNoUnrelatedLegacyAccountUsage($context);
        $snapshotPath = $this->writeSnapshot($context);

        DB::transaction(function () use ($context): void {
            $this->deleteGeneratedJournals($context['journal_ids']);
            $this->deleteGeneratedExpensePayments($context['expense_ids']);
            $this->deleteGeneratedStock($context);
            $this->resetBatch($context['batch']->id);
            $this->removeLegacyProductionAccounts($context);
        }, 3);

        $this->newLine();
        $this->info('Production test batch reset completed.');
        $this->line('Snapshot: '.$snapshotPath);
        $this->line('Batch '.$context['batch']->batch_no.' is Draft again and can be completed with the new flow.');

        return self::SUCCESS;
    }

    private function assertSafeEnvironment(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('This command is restricted to the local environment.');
        }

        if (DB::getDatabaseName() !== 'pos-sinetec') {
            throw new RuntimeException(
                'Refusing to run: the active database must be exactly [pos-sinetec].',
            );
        }
    }

    /** @return array<string, mixed> */
    private function batchContext(int $batchId): array
    {
        $batch = DB::table('part_production_batches')->where('id', $batchId)->first();

        if (! $batch) {
            throw new RuntimeException("Part production batch [{$batchId}] does not exist.");
        }

        $allocationIds = DB::table('part_production_expenses')
            ->where('part_production_batch_id', $batchId)
            ->pluck('id');

        $expenseIds = DB::table('expenses')
            ->where(function ($query) use ($batchId, $allocationIds): void {
                if (Schema::hasColumn('expenses', 'part_production_batch_id')) {
                    $query->where('part_production_batch_id', $batchId);
                }

                if (
                    $allocationIds->isNotEmpty()
                    && Schema::hasColumn('expenses', 'part_production_expense_id')
                ) {
                    $query->orWhereIn('part_production_expense_id', $allocationIds);
                }
            })
            ->pluck('id');

        if (Schema::hasTable('expense_items') && $allocationIds->isNotEmpty()) {
            $itemExpenseIds = DB::table('expense_items')
                ->whereIn('part_production_expense_id', $allocationIds)
                ->pluck('expense_id');
            $expenseIds = $expenseIds->merge($itemExpenseIds)->unique()->values();
        }

        $journalIds = DB::table('journal_entries')
            ->where(function ($query) use ($batchId, $expenseIds): void {
                $query
                    ->where(function ($productionQuery) use ($batchId): void {
                        $productionQuery
                            ->where('source_type', 'part_production_batch')
                            ->where('source_id', $batchId);
                    });

                if ($expenseIds->isNotEmpty()) {
                    $query->orWhere(function ($expenseQuery) use ($expenseIds): void {
                        $expenseQuery
                            ->where('source_type', 'expense')
                            ->whereIn('source_id', $expenseIds);
                    });
                }
            })
            ->pluck('id');

        $rawMovements = DB::table('raw_material_stock_movements')
            ->where('source_type', 'part_production_batch')
            ->where('source_id', $batchId)
            ->get();
        $partMovements = DB::table('part_stock_movements')
            ->where('source_type', 'part_production_batch')
            ->where('source_id', $batchId)
            ->get();
        $legacyAccounts = DB::table('accounts')
            ->whereIn('code', self::LEGACY_PRODUCTION_ACCOUNT_CODES)
            ->get();
        $currentRawBalances = DB::table('part_production_consumptions as consumption')
            ->leftJoin('raw_material_stock_balances as balance', function ($join) use ($batch): void {
                $join
                    ->on('balance.raw_material_id', '=', 'consumption.raw_material_id')
                    ->where('balance.warehouse_id', '=', $batch->input_warehouse_id);
            })
            ->where('consumption.part_production_batch_id', $batchId)
            ->get([
                'consumption.raw_material_id',
                DB::raw('COALESCE(balance.quantity, 0) as quantity'),
            ]);
        $currentPartBalance = DB::table('part_production_batches as batch')
            ->join('part_boms as bom', 'bom.id', '=', 'batch.part_bom_id')
            ->leftJoin('part_stock_balances as balance', function ($join): void {
                $join
                    ->on('balance.part_id', '=', 'bom.part_id')
                    ->on('balance.warehouse_id', '=', 'batch.output_warehouse_id');
            })
            ->where('batch.id', $batchId)
            ->value(DB::raw('COALESCE(balance.quantity, 0)'));

        return [
            'batch' => $batch,
            'allocation_ids' => $allocationIds,
            'expense_ids' => $expenseIds,
            'journal_ids' => $journalIds,
            'raw_movements' => $rawMovements,
            'part_movements' => $partMovements,
            'legacy_accounts' => $legacyAccounts,
            'current_raw_balances' => $currentRawBalances,
            'current_part_balance' => $currentPartBalance,
        ];
    }

    /** @param array<string, mixed> $context */
    private function displayAudit(array $context): void
    {
        $this->info('Target audit');
        $this->table(
            ['Target', 'Count / Value'],
            [
                ['Database', DB::getDatabaseName()],
                ['Batch', $context['batch']->batch_no.' (#'.$context['batch']->id.')'],
                ['Expense allocation lines', $context['allocation_ids']->count()],
                ['Generated expense payments', $context['expense_ids']->count()],
                ['Generated journals', $context['journal_ids']->count()],
                ['Raw-material stock movements', $context['raw_movements']->count()],
                ['Part stock movements', $context['part_movements']->count()],
                ['Legacy production accounts', $context['legacy_accounts']->count()],
                [
                    'Current raw-material balance(s)',
                    $context['current_raw_balances']
                        ->map(fn (object $row): string => "RM#{$row->raw_material_id}: {$row->quantity}")
                        ->implode(', '),
                ],
                ['Current output-part balance', $context['current_part_balance']],
            ],
        );
    }

    /** @param array<string, mixed> $context */
    private function assertNoUnrelatedLegacyAccountUsage(array $context): void
    {
        $accountIds = $context['legacy_accounts']->pluck('id');

        if ($accountIds->isEmpty()) {
            return;
        }

        $hasOtherProductionAllocations = DB::table('part_production_expenses')
            ->whereIn('account_id', $accountIds)
            ->where('part_production_batch_id', '!=', $context['batch']->id)
            ->exists();

        if ($hasOtherProductionAllocations) {
            throw new RuntimeException(
                'Legacy production accounts are used by another production batch. Cleanup stopped.',
            );
        }

        $hasGeneralExpenseCategory = DB::table('expense_categories')
            ->whereIn('expense_account_id', $accountIds)
            ->where('category_scope', 'GENERAL')
            ->exists();

        if ($hasGeneralExpenseCategory) {
            throw new RuntimeException(
                'A legacy production account is used by a General Expense Category. Cleanup stopped.',
            );
        }

        $checks = [
            'journal_entry_lines' => ['account_id', $context['journal_ids']],
            'account_opening_balances' => ['account_id', collect()],
            'purchase_payments' => ['account_id', collect()],
            'expense_payments' => ['account_id', collect()],
            'sale_payments' => ['account_id', collect()],
            'sale_return_payments' => ['account_id', collect()],
            'finance_capital_investments' => ['capital_account_id', collect()],
            'finance_capital_withdrawals' => ['drawings_account_id', collect()],
            'finance_loan_receipts' => ['loan_account_id', collect()],
            'finance_cash_bank_transfers' => ['from_account_id', collect()],
            'finance_fiscal_year_closings' => ['drawings_account_id', collect()],
            'product_assembly_expenses' => ['account_id', collect()],
        ];

        foreach ($checks as $table => [$column, $allowedJournalIds]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $query = DB::table($table)->whereIn($column, $accountIds);

            if ($table === 'journal_entry_lines' && $allowedJournalIds->isNotEmpty()) {
                $query->whereNotIn('journal_entry_id', $allowedJournalIds);
            }

            if ($query->exists()) {
                throw new RuntimeException(
                    "Legacy production accounts have unrelated references in [{$table}.{$column}]. Cleanup stopped.",
                );
            }
        }

        if (DB::table('finance_account_mappings')->whereIn('account_id', $accountIds)->exists()) {
            throw new RuntimeException(
                'A legacy production account is still used in Finance Account Mapping. Cleanup stopped.',
            );
        }
    }

    /** @param array<string, mixed> $context */
    private function writeSnapshot(array $context): string
    {
        $expenseIds = $context['expense_ids'];
        $journalIds = $context['journal_ids'];
        $accountIds = $context['legacy_accounts']->pluck('id');

        $snapshot = [
            'created_at' => now()->toIso8601String(),
            'database' => DB::getDatabaseName(),
            'batch' => $context['batch'],
            'consumptions' => DB::table('part_production_consumptions')
                ->where('part_production_batch_id', $context['batch']->id)
                ->get(),
            'production_expenses' => DB::table('part_production_expenses')
                ->whereIn('id', $context['allocation_ids'])
                ->get(),
            'expenses' => DB::table('expenses')->whereIn('id', $expenseIds)->get(),
            'expense_items' => Schema::hasTable('expense_items')
                ? DB::table('expense_items')->whereIn('expense_id', $expenseIds)->get()
                : [],
            'expense_payments' => DB::table('expense_payments')->whereIn('expense_id', $expenseIds)->get(),
            'journals' => DB::table('journal_entries')->whereIn('id', $journalIds)->get(),
            'journal_lines' => DB::table('journal_entry_lines')->whereIn('journal_entry_id', $journalIds)->get(),
            'raw_material_stock_movements' => $context['raw_movements'],
            'part_stock_movements' => $context['part_movements'],
            'raw_material_stock_balances' => $this->rawBalancesFor($context['raw_movements']),
            'part_stock_balances' => $this->partBalancesFor($context['part_movements']),
            'legacy_accounts' => $context['legacy_accounts'],
            'legacy_branch_accounts' => DB::table('branch_accounts')->whereIn('account_id', $accountIds)->get(),
            'production_categories' => DB::table('production_expense_categories')
                ->whereIn('expense_account_id', $accountIds)
                ->get(),
            'synced_expense_categories' => DB::table('expense_categories')
                ->where('category_scope', 'PRODUCTION')
                ->whereIn('expense_account_id', $accountIds)
                ->get(),
        ];

        $fileName = 'backups/production-test-reset-'
            .$context['batch']->id.'-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put(
            $fileName,
            json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return Storage::disk('local')->path($fileName);
    }

    /** @param Collection<int, object> $movements */
    private function rawBalancesFor(Collection $movements): Collection
    {
        return $movements
            ->map(fn (object $movement): string => $movement->raw_material_id.':'.$movement->warehouse_id)
            ->unique()
            ->map(function (string $key): ?object {
                [$materialId, $warehouseId] = array_map('intval', explode(':', $key));

                return DB::table('raw_material_stock_balances')
                    ->where('raw_material_id', $materialId)
                    ->where('warehouse_id', $warehouseId)
                    ->first();
            })
            ->filter()
            ->values();
    }

    /** @param Collection<int, object> $movements */
    private function partBalancesFor(Collection $movements): Collection
    {
        return $movements
            ->map(fn (object $movement): string => $movement->part_id.':'.$movement->warehouse_id)
            ->unique()
            ->map(function (string $key): ?object {
                [$partId, $warehouseId] = array_map('intval', explode(':', $key));

                return DB::table('part_stock_balances')
                    ->where('part_id', $partId)
                    ->where('warehouse_id', $warehouseId)
                    ->first();
            })
            ->filter()
            ->values();
    }

    /** @param Collection<int, int> $journalIds */
    private function deleteGeneratedJournals(Collection $journalIds): void
    {
        DB::table('journal_entry_lines')->whereIn('journal_entry_id', $journalIds)->delete();
        DB::table('journal_entries')->whereIn('id', $journalIds)->delete();
    }

    /** @param Collection<int, int> $expenseIds */
    private function deleteGeneratedExpensePayments(Collection $expenseIds): void
    {
        if (Schema::hasTable('expense_items')) {
            DB::table('expense_items')->whereIn('expense_id', $expenseIds)->delete();
        }

        DB::table('expense_payments')->whereIn('expense_id', $expenseIds)->delete();
        DB::table('expenses')->whereIn('id', $expenseIds)->delete();
    }

    /** @param array<string, mixed> $context */
    private function deleteGeneratedStock(array $context): void
    {
        DB::table('raw_material_stock_movements')
            ->whereIn('id', $context['raw_movements']->pluck('id'))
            ->delete();
        DB::table('part_stock_movements')
            ->whereIn('id', $context['part_movements']->pluck('id'))
            ->delete();

        foreach ($context['raw_movements'] as $movement) {
            $this->recalculateRawBalance(
                (int) $movement->raw_material_id,
                (int) $movement->warehouse_id,
            );
        }

        foreach ($context['part_movements'] as $movement) {
            $this->recalculatePartBalance(
                (int) $movement->part_id,
                (int) $movement->warehouse_id,
            );
        }
    }

    private function recalculateRawBalance(int $materialId, int $warehouseId): void
    {
        $quantity = DB::table('raw_material_stock_movements')
            ->where('raw_material_id', $materialId)
            ->where('warehouse_id', $warehouseId)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'IN' THEN quantity ELSE -quantity END), 0) quantity")
            ->value('quantity');

        DB::table('raw_material_stock_balances')->updateOrInsert(
            ['raw_material_id' => $materialId, 'warehouse_id' => $warehouseId],
            ['quantity' => $quantity, 'version' => 0, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    private function recalculatePartBalance(int $partId, int $warehouseId): void
    {
        $quantity = DB::table('part_stock_movements')
            ->where('part_id', $partId)
            ->where('warehouse_id', $warehouseId)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'IN' THEN quantity ELSE -quantity END), 0) quantity")
            ->value('quantity');

        DB::table('part_stock_balances')->updateOrInsert(
            ['part_id' => $partId, 'warehouse_id' => $warehouseId],
            ['quantity' => $quantity, 'version' => 0, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    private function resetBatch(int $batchId): void
    {
        DB::table('part_production_consumptions')
            ->where('part_production_batch_id', $batchId)
            ->update([
                'unit_cost' => 0,
                'total_cost' => 0,
                'updated_at' => now(),
            ]);
        DB::table('part_production_expenses')
            ->where('part_production_batch_id', $batchId)
            ->update(['account_id' => null, 'updated_at' => now()]);
        DB::table('part_production_batches')
            ->where('id', $batchId)
            ->update([
                'status' => 'DRAFT',
                'completed_by' => null,
                'completed_at' => null,
                'updated_at' => now(),
            ]);
    }

    /** @param array<string, mixed> $context */
    private function removeLegacyProductionAccounts(array $context): void
    {
        $accountIds = $context['legacy_accounts']->pluck('id');

        if ($accountIds->isEmpty()) {
            return;
        }

        DB::table('production_expense_categories')
            ->whereIn('expense_account_id', $accountIds)
            ->update(['expense_account_id' => null, 'updated_at' => now()]);
        DB::table('expense_categories')
            ->where('category_scope', 'PRODUCTION')
            ->whereIn('expense_account_id', $accountIds)
            ->delete();
        DB::table('branch_accounts')->whereIn('account_id', $accountIds)->delete();

        DB::table('accounts')
            ->whereIn('id', $accountIds)
            ->whereNotNull('parent_id')
            ->delete();
        DB::table('accounts')
            ->whereIn('id', $accountIds)
            ->whereNull('parent_id')
            ->delete();
    }
}
