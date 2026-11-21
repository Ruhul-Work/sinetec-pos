<?php

namespace App\Enums\Reporting;

enum MonthlyRateSheetStatus: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';
    case Locked = 'LOCKED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Locked => 'Locked',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-dark-warning-gradient text-white',
            self::Approved => 'bg-dark-info-gradient text-white',
            self::Locked => 'bg-dark-success-gradient text-white',
        };
    }
}
