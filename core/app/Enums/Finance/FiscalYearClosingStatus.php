<?php

namespace App\Enums\Finance;

enum FiscalYearClosingStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Void = 'VOID';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Prepared',
            self::Void => 'Void',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-dark-warning-gradient',
            self::Posted => 'bg-dark-success-gradient',
            self::Void => 'bg-dark-danger-gradient',
        };
    }
}
