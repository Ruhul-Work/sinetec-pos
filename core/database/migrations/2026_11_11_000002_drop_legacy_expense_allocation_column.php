<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $foreignKeyExists = collect(Schema::getForeignKeys('expenses'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === ['part_production_expense_id']
            );

        if ($foreignKeyExists) {
            Schema::table('expenses', function (Blueprint $table): void {
                // Dropping by column is portable. SQLite foreign keys do not
                // expose Laravel's generated constraint name consistently.
                $table->dropForeign(['part_production_expense_id']);
            });
        }

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropUnique('expenses_production_expense_unique');
            $table->dropColumn('part_production_expense_id');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignId('part_production_expense_id')
                ->nullable()
                ->after('part_production_batch_id')
                ->unique('expenses_production_expense_unique')
                ->constrained('part_production_expenses')
                ->restrictOnDelete();
        });
    }
};
