<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignId('part_production_batch_id')
                ->nullable()
                ->after('expense_category_id')
                ->constrained('part_production_batches')
                ->restrictOnDelete();

            $table->foreignId('expense_category_id')->nullable()->change();
        });

        Schema::create('expense_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')
                ->constrained('expenses')
                ->cascadeOnDelete();
            $table->foreignId('part_production_expense_id')
                ->constrained('part_production_expenses')
                ->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(
                ['expense_id', 'part_production_expense_id'],
                'expense_items_expense_allocation_unique',
            );
            $table->index(
                'part_production_expense_id',
                'expense_items_allocation_index',
            );
        });

        $this->migrateLegacyProductionSettlements();
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_items');

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropForeign(['part_production_batch_id']);
            $table->dropColumn('part_production_batch_id');
            $table->foreignId('expense_category_id')->nullable(false)->change();
        });
    }

    private function migrateLegacyProductionSettlements(): void
    {
        $legacySettlements = DB::table('expenses as expense')
            ->join(
                'part_production_expenses as production_expense',
                'production_expense.id',
                '=',
                'expense.part_production_expense_id',
            )
            ->whereNotNull('expense.part_production_expense_id')
            ->get([
                'expense.id as expense_id',
                'expense.part_production_expense_id',
                'expense.amount',
                'expense.created_at',
                'expense.updated_at',
                'production_expense.part_production_batch_id',
                'production_expense.note',
            ]);

        foreach ($legacySettlements as $settlement) {
            DB::table('expenses')
                ->where('id', $settlement->expense_id)
                ->update([
                    'part_production_batch_id' => $settlement->part_production_batch_id,
                ]);

            DB::table('expense_items')->insertOrIgnore([
                'expense_id' => $settlement->expense_id,
                'part_production_expense_id' => $settlement->part_production_expense_id,
                'amount' => $settlement->amount,
                'note' => $settlement->note,
                'created_at' => $settlement->created_at,
                'updated_at' => $settlement->updated_at,
            ]);
        }
    }
};
