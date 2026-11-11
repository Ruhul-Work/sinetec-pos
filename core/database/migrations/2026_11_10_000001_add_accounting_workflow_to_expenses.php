<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignId('part_production_expense_id')
                ->nullable()
                ->after('expense_category_id')
                ->unique('expenses_production_expense_unique')
                ->constrained('part_production_expenses')
                ->restrictOnDelete();
            $table->foreignId('posted_by')
                ->nullable()
                ->after('approved_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('posted_at')->nullable()->after('posted_by');
            $table->foreignId('voided_by')
                ->nullable()
                ->after('posted_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('voided_by');
            $table->text('void_reason')->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropForeign(['part_production_expense_id']);
            $table->dropForeign(['posted_by']);
            $table->dropForeign(['voided_by']);
            $table->dropUnique('expenses_production_expense_unique');
            $table->dropColumn([
                'part_production_expense_id',
                'posted_by',
                'posted_at',
                'voided_by',
                'voided_at',
                'void_reason',
            ]);
        });
    }
};
