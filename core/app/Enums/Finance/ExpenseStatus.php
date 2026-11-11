<?php

namespace App\Enums\Finance;

enum ExpenseStatus: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';
    case Posted = 'POSTED';
    case Void = 'VOID';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Posted => 'Recorded',
            self::Void => 'Void',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-dark-warning-gradient',
            self::Approved => 'bg-info',
            self::Posted => 'bg-dark-success-gradient',
            self::Void => 'bg-dark-danger-gradient',
        };
    }
}
