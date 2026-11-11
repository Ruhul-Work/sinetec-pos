<?php

namespace App\Services\Finance;

use App\Models\Finance\ExpenseCategory;
use App\Models\Production\ProductionExpenseCategory;
use Illuminate\Validation\ValidationException;

final class ExpenseCategorySyncService
{
    public function syncProductionCategory(
        ProductionExpenseCategory $productionCategory,
        ?string $previousCode = null,
    ): ExpenseCategory {
        $financeCategory = $this->existingProductionCategory(
            $productionCategory->code,
            $previousCode,
        );

        $financeCategory ??= new ExpenseCategory;
        $financeCategory->fill([
            'code' => $productionCategory->code,
            'name' => $productionCategory->name,
            'category_scope' => 'PRODUCTION',
            'expense_account_id' => $productionCategory->expense_account_id,
            'is_active' => $productionCategory->is_active,
        ]);
        $financeCategory->save();

        return $financeCategory;
    }

    private function existingProductionCategory(
        string $code,
        ?string $previousCode,
    ): ?ExpenseCategory {
        $desiredCategory = ExpenseCategory::query()
            ->where('code', $code)
            ->first();

        if ($desiredCategory?->category_scope === 'GENERAL') {
            throw ValidationException::withMessages([
                'code' => 'This code is already used by a General Expense Category.',
            ]);
        }

        if ($desiredCategory) {
            return $desiredCategory;
        }

        if (! $previousCode || $previousCode === $code) {
            return null;
        }

        return ExpenseCategory::query()
            ->where('category_scope', 'PRODUCTION')
            ->where('code', $previousCode)
            ->first();
    }
}
