<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PurgeProductionTestData extends Command
{
    protected $signature = 'production:purge-test-data
        {--execute : Create a JSON snapshot and permanently remove all Part Production test transactions}';

    protected $description = 'Safely purge all local Part Production test transactions and rebuild affected stock balances';

    public function handle(): int
    {
        $this->assertSafeEnvironment();

        $context = $this->buildContext();
        $this->displayAudit($context);

        if ($context['batch_ids']->isEmpty()) {
            $this->info('No Part Production batch data was found. Nothing needs to be cleaned.');

            return self::SUCCESS;
        }

        if (! $this->option('execute')) {
            $this->warn('Dry run only. Re-run with --execute to create a snapshot and delete these exact transactions.');

            return self::SUCCESS;
        }

        $snapshotPath = $this->writeSnapshot($context);

        DB::transaction(function () use ($context): void {
            $this->deleteJournals($context['journal_ids']);
            $this->deleteSettlementExpenses($context['expense_ids']);
            $this->deleteStockMovements($context);
            $this->deleteProductionBatches($context['batch_ids']);
        }, 3);

        $this->verifyCleanup($context);

        $this->newLine();
        $this->info('All Part Production test transactions were cleaned successfully.');
        $this->line('Recovery snapshot: '.$snapshotPath);
        $this->line('BOM/Recipe and Production Expense Category setup were kept for the next test.');
        $this->line('Purchase, Opening Stock, Stock Transfer and Stock Adjustment data were not changed.');

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
    private function buildContext(): array
    {
        $batchIds = DB::table('part_production_batches')
            ->orderBy('id')
            ->pluck('id');

        $allocationIds = DB::table('part_production_expenses')
            ->whereIn('part_production_batch_id', $batchIds)
            ->pluck('id');

        $expenseIds = $this->productionSettlementExpenseIds($batchIds, $allocationIds);
        $journalIds = $this->relatedJournalIds($batchIds, $expenseIds);
        $rawMovements = $this->relatedStockMovements(
            'raw_material_stock_movements',
            $batchIds,
        );
        $partMovements = $this->relatedStockMovements(
            'part_stock_movements',
            $batchIds,
        );

        return [
            'batch_ids' => $batchIds,
            'allocation_ids' => $allocationIds,
            'expense_ids' => $expenseIds,
            'journal_ids' => $journalIds,
            'raw_movements' => $rawMovements,
            'part_movements' => $partMovements,
            'raw_balance_keys' => $this->stockBalanceKeys(
                $rawMovements,
                'raw_material_id',
            ),
            'part_balance_keys' => $this->stockBalanceKeys(
                $partMovements,
                'part_id',
            ),
        ];
    }

    /**
     * @param  Collection<int, int>  $batchIds
     * @param  Collection<int, int>  $allocationIds
     * @return Collection<int, int>
     */
    private function productionSettlementExpenseIds(
        Collection $batchIds,
        Collection $allocationIds,
    ): Collection {
        if (! Schema::hasTable('expenses')) {
            return collect();
        }

        $expenseIds = collect();

        if (Schema::hasColumn('expenses', 'part_production_batch_id')) {
            $expenseIds = DB::table('expenses')
                ->whereIn('part_production_batch_id', $batchIds)
                ->pluck('id');
        }

        if (
            Schema::hasColumn('expenses', 'part_production_expense_id')
            && $allocationIds->isNotEmpty()
        ) {
            $legacyExpenseIds = DB::table('expenses')
                ->whereIn('part_production_expense_id', $allocationIds)
                ->pluck('id');
            $expenseIds = $expenseIds->merge($legacyExpenseIds);
        }

        if (Schema::hasTable('expense_items') && $allocationIds->isNotEmpty()) {
            $itemExpenseIds = DB::table('expense_items')
                ->whereIn('part_production_expense_id', $allocationIds)
                ->pluck('expense_id');
            $expenseIds = $expenseIds->merge($itemExpenseIds);
        }

        return $expenseIds
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, int>  $batchIds
     * @param  Collection<int, int>  $expenseIds
     * @return Collection<int, int>
     */
    private function relatedJournalIds(
        Collection $batchIds,
        Collection $expenseIds,
    ): Collection {
        $directJournalIds = DB::table('journal_entries')
            ->where(function (Builder $query) use ($batchIds, $expenseIds): void {
                $query->where(function (Builder $productionQuery) use ($batchIds): void {
                    $productionQuery
                        ->where('source_type', 'part_production_batch')
                        ->whereIn('source_id', $batchIds);
                });

                if ($expenseIds->isNotEmpty()) {
                    $query->orWhere(function (Builder $expenseQuery) use ($expenseIds): void {
                        $expenseQuery
                            ->where('source_type', 'expense')
                            ->whereIn('source_id', $expenseIds);
                    });
                }
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        return $this->includeReversalDescendants('journal_entries', $directJournalIds);
    }

    /**
     * @param  Collection<int, int>  $batchIds
     * @return Collection<int, object>
     */
    private function relatedStockMovements(string $table, Collection $batchIds): Collection
    {
        $directIds = DB::table($table)
            ->where('source_type', 'part_production_batch')
            ->whereIn('source_id', $batchIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        $movementIds = $this->includeReversalDescendants($table, $directIds);

        return DB::table($table)
            ->whereIn('id', $movementIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, int>  $directIds
     * @return Collection<int, int>
     */
    private function includeReversalDescendants(string $table, Collection $directIds): Collection
    {
        $allIds = $directIds->unique()->values();
        $parentIds = $allIds;

        while ($parentIds->isNotEmpty()) {
            $childIds = DB::table($table)
                ->whereIn('reversal_of_id', $parentIds)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->diff($allIds)
                ->values();

            if ($childIds->isEmpty()) {
                break;
            }

            $allIds = $allIds->merge($childIds)->unique()->values();
            $parentIds = $childIds;
        }

        return $allIds;
    }

    /**
     * @param  Collection<int, object>  $movements
     * @return Collection<int, string>
     */
    private function stockBalanceKeys(Collection $movements, string $itemColumn): Collection
    {
        return $movements
            ->map(
                fn (object $movement): string => $movement->{$itemColumn}
                    .':'.$movement->warehouse_id,
            )
            ->unique()
            ->values();
    }

    /** @param array<string, mixed> $context */
    private function displayAudit(array $context): void
    {
        $statusCounts = DB::table('part_production_batches')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total);

        $this->info('Part Production cleanup audit');
        $this->table(
            ['Target', 'Count / Value'],
            [
                ['Database', DB::getDatabaseName()],
                ['Production batches', $context['batch_ids']->count()],
                ['Batch statuses', $statusCounts->map(
                    fn (int $count, string $status): string => "{$status}: {$count}",
                )->implode(', ') ?: 'None'],
                ['Consumption rows', DB::table('part_production_consumptions')
                    ->whereIn('part_production_batch_id', $context['batch_ids'])
                    ->count()],
                ['Production expense rows', $context['allocation_ids']->count()],
                ['Legacy settlement expenses', $context['expense_ids']->count()],
                ['Related journals (including reversals)', $context['journal_ids']->count()],
                ['Raw-material movements (including reversals)', $context['raw_movements']->count()],
                ['Part movements (including reversals)', $context['part_movements']->count()],
                ['Affected raw-material balances', $context['raw_balance_keys']->count()],
                ['Affected part balances', $context['part_balance_keys']->count()],
            ],
        );
    }

    /** @param array<string, mixed> $context */
    private function writeSnapshot(array $context): string
    {
        $expenseIds = $context['expense_ids'];
        $journalIds = $context['journal_ids'];

        $snapshot = [
            'created_at' => now()->toIso8601String(),
            'database' => DB::getDatabaseName(),
            'scope' => 'All Part Production test transactions',
            'part_production_batches' => DB::table('part_production_batches')
                ->whereIn('id', $context['batch_ids'])
                ->orderBy('id')
                ->get(),
            'part_production_consumptions' => DB::table('part_production_consumptions')
                ->whereIn('part_production_batch_id', $context['batch_ids'])
                ->orderBy('id')
                ->get(),
            'part_production_expenses' => DB::table('part_production_expenses')
                ->whereIn('id', $context['allocation_ids'])
                ->orderBy('id')
                ->get(),
            'expenses' => DB::table('expenses')
                ->whereIn('id', $expenseIds)
                ->orderBy('id')
                ->get(),
            'expense_items' => Schema::hasTable('expense_items')
                ? DB::table('expense_items')->whereIn('expense_id', $expenseIds)->orderBy('id')->get()
                : [],
            'expense_payments' => DB::table('expense_payments')
                ->whereIn('expense_id', $expenseIds)
                ->orderBy('id')
                ->get(),
            'journal_entries' => DB::table('journal_entries')
                ->whereIn('id', $journalIds)
                ->orderBy('id')
                ->get(),
            'journal_entry_lines' => DB::table('journal_entry_lines')
                ->whereIn('journal_entry_id', $journalIds)
                ->orderBy('id')
                ->get(),
            'raw_material_stock_movements' => $context['raw_movements'],
            'part_stock_movements' => $context['part_movements'],
            'raw_material_stock_balances' => $this->balanceRows(
                'raw_material_stock_balances',
                'raw_material_id',
                $context['raw_balance_keys'],
            ),
            'part_stock_balances' => $this->balanceRows(
                'part_stock_balances',
                'part_id',
                $context['part_balance_keys'],
            ),
        ];

        $fileName = 'backups/part-production-purge-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put(
            $fileName,
            json_encode(
                $snapshot,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        );

        return Storage::disk('local')->path($fileName);
    }

    /**
     * @param  Collection<int, string>  $keys
     * @return Collection<int, object>
     */
    private function balanceRows(
        string $table,
        string $itemColumn,
        Collection $keys,
    ): Collection {
        return $keys
            ->map(function (string $key) use ($table, $itemColumn): ?object {
                [$itemId, $warehouseId] = array_map('intval', explode(':', $key));

                return DB::table($table)
                    ->where($itemColumn, $itemId)
                    ->where('warehouse_id', $warehouseId)
                    ->first();
            })
            ->filter()
            ->values();
    }

    /** @param Collection<int, int> $journalIds */
    private function deleteJournals(Collection $journalIds): void
    {
        if ($journalIds->isEmpty()) {
            return;
        }

        DB::table('journal_entry_lines')
            ->whereIn('journal_entry_id', $journalIds)
            ->delete();

        $this->deleteSelfReferencingRows('journal_entries', $journalIds);
    }

    /** @param Collection<int, int> $expenseIds */
    private function deleteSettlementExpenses(Collection $expenseIds): void
    {
        if ($expenseIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('expense_items')) {
            DB::table('expense_items')->whereIn('expense_id', $expenseIds)->delete();
        }

        DB::table('expense_payments')->whereIn('expense_id', $expenseIds)->delete();
        DB::table('expenses')->whereIn('id', $expenseIds)->delete();
    }

    /** @param array<string, mixed> $context */
    private function deleteStockMovements(array $context): void
    {
        $rawMovementIds = $context['raw_movements']->pluck('id');
        $partMovementIds = $context['part_movements']->pluck('id');

        $this->deleteSelfReferencingRows(
            'raw_material_stock_movements',
            $rawMovementIds,
        );
        $this->deleteSelfReferencingRows('part_stock_movements', $partMovementIds);

        foreach ($context['raw_balance_keys'] as $key) {
            [$materialId, $warehouseId] = array_map('intval', explode(':', $key));
            $this->recalculateStockBalance(
                'raw_material_stock_movements',
                'raw_material_stock_balances',
                'raw_material_id',
                $materialId,
                $warehouseId,
            );
        }

        foreach ($context['part_balance_keys'] as $key) {
            [$partId, $warehouseId] = array_map('intval', explode(':', $key));
            $this->recalculateStockBalance(
                'part_stock_movements',
                'part_stock_balances',
                'part_id',
                $partId,
                $warehouseId,
            );
        }
    }

    /** @param Collection<int, int> $rowIds */
    private function deleteSelfReferencingRows(string $table, Collection $rowIds): void
    {
        $remainingIds = $rowIds
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        while ($remainingIds->isNotEmpty()) {
            $parentIds = DB::table($table)
                ->whereIn('id', $remainingIds)
                ->whereIn('reversal_of_id', $remainingIds)
                ->pluck('reversal_of_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique();
            $leafIds = $remainingIds->diff($parentIds)->values();

            if ($leafIds->isEmpty()) {
                throw new RuntimeException(
                    "Cannot safely delete [{$table}]: a reversal reference cycle was detected.",
                );
            }

            DB::table($table)->whereIn('id', $leafIds)->delete();
            $remainingIds = $remainingIds->diff($leafIds)->values();
        }
    }

    private function recalculateStockBalance(
        string $movementTable,
        string $balanceTable,
        string $itemColumn,
        int $itemId,
        int $warehouseId,
    ): void {
        $movementQuery = DB::table($movementTable)
            ->where($itemColumn, $itemId)
            ->where('warehouse_id', $warehouseId);

        if (! $movementQuery->exists()) {
            DB::table($balanceTable)
                ->where($itemColumn, $itemId)
                ->where('warehouse_id', $warehouseId)
                ->delete();

            return;
        }

        $quantity = (string) $movementQuery
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN direction = 'IN' THEN quantity ELSE -quantity END), 0) quantity",
            )
            ->value('quantity');

        $balanceQuery = DB::table($balanceTable)
            ->where($itemColumn, $itemId)
            ->where('warehouse_id', $warehouseId);

        if ($balanceQuery->exists()) {
            $balanceQuery->update([
                'quantity' => $quantity,
                'version' => 0,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table($balanceTable)->insert([
            $itemColumn => $itemId,
            'warehouse_id' => $warehouseId,
            'quantity' => $quantity,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param Collection<int, int> $batchIds */
    private function deleteProductionBatches(Collection $batchIds): void
    {
        if (Schema::hasTable('expense_items')) {
            DB::table('expense_items')
                ->whereIn(
                    'part_production_expense_id',
                    DB::table('part_production_expenses')
                        ->whereIn('part_production_batch_id', $batchIds)
                        ->select('id'),
                )
                ->delete();
        }

        DB::table('part_production_expenses')
            ->whereIn('part_production_batch_id', $batchIds)
            ->delete();
        DB::table('part_production_consumptions')
            ->whereIn('part_production_batch_id', $batchIds)
            ->delete();
        DB::table('part_production_batches')->whereIn('id', $batchIds)->delete();
    }

    /** @param array<string, mixed> $context */
    private function verifyCleanup(array $context): void
    {
        $checks = [
            'part_production_batches' => DB::table('part_production_batches')->count(),
            'part_production_consumptions' => DB::table('part_production_consumptions')->count(),
            'part_production_expenses' => DB::table('part_production_expenses')->count(),
            'production raw-material movements' => DB::table('raw_material_stock_movements')
                ->where('source_type', 'part_production_batch')
                ->count(),
            'production part movements' => DB::table('part_stock_movements')
                ->where('source_type', 'part_production_batch')
                ->count(),
            'production journals' => DB::table('journal_entries')
                ->where('source_type', 'part_production_batch')
                ->count(),
        ];

        $remaining = collect($checks)->filter(fn (int $count): bool => $count > 0);

        if ($remaining->isNotEmpty()) {
            throw new RuntimeException(
                'Cleanup verification failed: '.$remaining
                    ->map(fn (int $count, string $label): string => "{$label}={$count}")
                    ->implode(', '),
            );
        }

        $this->verifyStockBalances(
            'raw_material_stock_movements',
            'raw_material_stock_balances',
            'raw_material_id',
            $context['raw_balance_keys'],
        );
        $this->verifyStockBalances(
            'part_stock_movements',
            'part_stock_balances',
            'part_id',
            $context['part_balance_keys'],
        );
    }

    /** @param Collection<int, string> $keys */
    private function verifyStockBalances(
        string $movementTable,
        string $balanceTable,
        string $itemColumn,
        Collection $keys,
    ): void {
        foreach ($keys as $key) {
            [$itemId, $warehouseId] = array_map('intval', explode(':', $key));

            $ledgerQuantity = (float) DB::table($movementTable)
                ->where($itemColumn, $itemId)
                ->where('warehouse_id', $warehouseId)
                ->selectRaw(
                    "COALESCE(SUM(CASE WHEN direction = 'IN' THEN quantity ELSE -quantity END), 0) quantity",
                )
                ->value('quantity');
            $balanceQuantity = (float) (DB::table($balanceTable)
                ->where($itemColumn, $itemId)
                ->where('warehouse_id', $warehouseId)
                ->value('quantity') ?? 0);

            if (abs($ledgerQuantity - $balanceQuantity) > 0.000001) {
                throw new RuntimeException(
                    "Stock balance verification failed for [{$balanceTable}] item "
                    ."[{$itemId}] in warehouse [{$warehouseId}].",
                );
            }
        }
    }
}
