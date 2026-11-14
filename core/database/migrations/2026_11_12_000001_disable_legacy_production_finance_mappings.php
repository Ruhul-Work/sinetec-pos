<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_MAPPING_KEYS = [
        'PRODUCTION_EXPENSE_PAYABLE',
        'PRODUCTION_LOSS',
    ];

    private const LEGACY_ACCOUNT_CODES = [
        '2050',
        '5100',
    ];

    public function up(): void
    {
        if (Schema::hasTable('finance_account_mappings')) {
            DB::table('finance_account_mappings')
                ->whereIn('mapping_key', self::LEGACY_MAPPING_KEYS)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
        }

        if (! Schema::hasTable('accounts')) {
            return;
        }

        $unusedAccountIds = DB::table('accounts')
            ->whereIn('code', self::LEGACY_ACCOUNT_CODES)
            ->where('is_system', true)
            ->when(
                Schema::hasTable('journal_entry_lines'),
                fn ($query) => $query->whereNotExists(function ($journalLines): void {
                    $journalLines
                        ->selectRaw('1')
                        ->from('journal_entry_lines')
                        ->whereColumn('journal_entry_lines.account_id', 'accounts.id');
                }),
            )
            ->when(
                Schema::hasTable('expense_categories'),
                fn ($query) => $query->whereNotExists(function ($categories): void {
                    $categories
                        ->selectRaw('1')
                        ->from('expense_categories')
                        ->whereColumn('expense_categories.expense_account_id', 'accounts.id');
                }),
            )
            ->pluck('id');

        if ($unusedAccountIds->isEmpty()) {
            return;
        }

        DB::table('accounts')
            ->whereIn('id', $unusedAccountIds)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        if (Schema::hasTable('branch_accounts')) {
            DB::table('branch_accounts')
                ->whereIn('account_id', $unusedAccountIds)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('finance_account_mappings')) {
            DB::table('finance_account_mappings')
                ->whereIn('mapping_key', self::LEGACY_MAPPING_KEYS)
                ->update([
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('accounts')) {
            $legacyAccountIds = DB::table('accounts')
                ->whereIn('code', self::LEGACY_ACCOUNT_CODES)
                ->where('is_system', true)
                ->pluck('id');

            DB::table('accounts')
                ->whereIn('id', $legacyAccountIds)
                ->update([
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

            if (Schema::hasTable('branch_accounts')) {
                DB::table('branch_accounts')
                    ->whereIn('account_id', $legacyAccountIds)
                    ->update([
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
            }
        }
    }
};
