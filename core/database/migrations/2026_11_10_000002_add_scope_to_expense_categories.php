<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table): void {
            $table->enum('category_scope', ['GENERAL', 'PRODUCTION'])
                ->default('GENERAL')
                ->after('name')
                ->index();
        });

        DB::table('expense_categories')
            ->whereIn(
                'code',
                DB::table('production_expense_categories')->select('code'),
            )
            ->update(['category_scope' => 'PRODUCTION']);
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table): void {
            $table->dropIndex(['category_scope']);
            $table->dropColumn('category_scope');
        });
    }
};
