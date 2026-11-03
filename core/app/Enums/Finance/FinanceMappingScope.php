<?php

namespace App\Enums\Finance;

enum FinanceMappingScope: string
{
    case Company = 'COMPANY';
    case Branch = 'BRANCH';

    public function scopeKey(?int $branchId = null): string
    {
        return $this === self::Company ? 'COMPANY' : 'BRANCH:'.(int) $branchId;
    }
}
